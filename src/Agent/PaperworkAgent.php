<?php

declare(strict_types=1);

namespace SafePath\Agent;

use SafePath\Infra\Db;
use SafePath\Model\MetaClient;

/**
 * Muse Spark writes the work orders and picks one subsidy code per hazard.
 * It only sees code-derived facts; a code outside the allowed list is rejected here.
 */
final class PaperworkAgent
{
    private const SYSTEM = <<<'TXT'
You write home-accessibility paperwork for a family and a long-term-care case manager in Taiwan.
Rules:
- This is about the home environment and administrative paperwork only. Never give health or medical advice, never mention fall risk scores, diagnoses or treatment.
- Use only the facts in the input. Never invent or change a number, measurement, price or code.
- For each hazard choose subsidy_code from its allowed_codes, or null if allowed_codes is empty.
- Write short, practical instructions: what to do and where.
- Output Traditional Chinese (Taiwan) in *_zh fields and plain English in *_en fields.
TXT;

    public function __construct(private MetaClient $meta)
    {
    }

    public function run(int $scanId): array
    {
        $pdo = Db::pdo();
        $q = $pdo->prepare('SELECT h.mobility FROM scans s JOIN households h ON h.id = s.household_id WHERE s.id = ?');
        $q->execute([$scanId]);
        $mobility = $q->fetchColumn();
        $q = $pdo->prepare("SELECT * FROM hazards WHERE scan_id = ? AND level IN ('red', 'yellow') ORDER BY id");
        $q->execute([$scanId]);
        $hazards = $q->fetchAll();

        $items = [];
        foreach ($hazards as $h) {
            $items[] = [
                'hazard_id' => (int) $h['id'],
                'room' => $h['room'],
                'hazard' => $h['name'],
                'level' => $h['level'],
                'finding' => $h['note'],
                'measured_cm' => $h['value_cm'] === null ? null : (float) $h['value_cm'],
                'allowed_codes' => json_decode($h['evidence_json'], true)['codes'] ?? [],
            ];
        }
        $pdo->prepare('DELETE FROM work_orders WHERE scan_id = ?')->execute([$scanId]);
        if ($items === []) {
            return ['hazards' => 0, 'orders' => 0];
        }

        $out = $this->meta->sparkJson(
            self::SYSTEM,
            json_encode(['resident_mobility' => $mobility, 'hazards' => $items], JSON_UNESCAPED_UNICODE),
            'work_orders',
            self::schema()
        );

        $byId = array_column($items, null, 'hazard_id');
        foreach ($out['orders'] as $order) {
            $id = (int) $order['hazard_id'];
            if (!isset($byId[$id])) {
                continue;
            }
            $allowed = $byId[$id]['allowed_codes'];
            $code = in_array($order['subsidy_code'], $allowed, true) ? $order['subsidy_code'] : ($allowed[0] ?? null);
            $action = $hazards[array_search($id, array_column($hazards, 'id'))]['action'];
            $pdo->prepare('INSERT INTO work_orders (scan_id, hazard_id, item, location, kind, text, text_en, subsidy_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$scanId, $id, $byId[$id]['hazard'], $byId[$id]['room'], $action, $order['instruction_zh'], $order['instruction_en'], $code]);
            unset($byId[$id]);
        }
        // Anything the model skipped still gets a plain order, so no hazard is lost.
        foreach ($byId as $id => $item) {
            $action = $hazards[array_search($id, array_column($hazards, 'id'))]['action'];
            $pdo->prepare('INSERT INTO work_orders (scan_id, hazard_id, item, location, kind, text, text_en, subsidy_code) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$scanId, $id, $item['hazard'], $item['room'], $action, $item['finding'], $item['hazard'], $item['allowed_codes'][0] ?? null]);
        }
        $pdo->prepare('UPDATE scans SET summary_json = ? WHERE id = ?')
            ->execute([json_encode(['summary_zh' => $out['summary_zh'], 'summary_en' => $out['summary_en']], JSON_UNESCAPED_UNICODE), $scanId]);
        return ['hazards' => count($items), 'orders' => count($out['orders'])];
    }

    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary_zh' => ['type' => 'string'],
                'summary_en' => ['type' => 'string'],
                'orders' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'hazard_id' => ['type' => 'integer'],
                            'instruction_zh' => ['type' => 'string'],
                            'instruction_en' => ['type' => 'string'],
                            'subsidy_code' => ['type' => ['string', 'null']],
                        ],
                        'required' => ['hazard_id', 'instruction_zh', 'instruction_en', 'subsidy_code'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['summary_zh', 'summary_en', 'orders'],
            'additionalProperties' => false,
        ];
    }
}
