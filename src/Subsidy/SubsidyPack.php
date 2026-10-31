<?php

declare(strict_types=1);

namespace SafePath\Subsidy;

use SafePath\Infra\Db;
use SafePath\Job\Pipeline;
use SafePath\Vision\SamTool;

/** Builds the draft package. All amounts come from the official table in config/subsidy, never from the LLM. */
final class SubsidyPack
{
    public function build(int $scanId): array
    {
        $root = Pipeline::root();
        $table = json_decode((string) file_get_contents($root . '/config/subsidy/tw-ltc2-2026.json'), true);
        $pdo = Db::pdo();
        $q = $pdo->prepare('SELECT s.simulated, h.* FROM scans s JOIN households h ON h.id = s.household_id WHERE s.id = ?');
        $q->execute([$scanId]);
        $home = $q->fetch();
        $copay = (int) $table['copay_percent'][(string) $home['ltc_category']];

        $q = $pdo->prepare("SELECT w.*, z.value_cm FROM work_orders w JOIN hazards z ON z.id = w.hazard_id
            WHERE w.scan_id = ? AND w.kind = 'construct' AND w.subsidy_code IS NOT NULL ORDER BY w.id");
        $q->execute([$scanId]);

        $items = [];
        $rows = [];
        $total = 0;
        $perUnit = false;
        foreach ($q->fetchAll() as $w) {
            $item = $table['items'][$w['subsidy_code']];
            $priced = $item['unit'] !== '每 10 公分';
            $cap = $item['cap_ntd'];
            $own = (int) floor($cap * $copay / 100);
            $total += $priced ? $cap : 0;
            $perUnit = $perUnit || !$priced;
            $where = $w['location'] . ($w['value_cm'] !== null ? sprintf('，量測約 %.1f 公分', $w['value_cm']) : '');
            $items[] = ['code' => $w['subsidy_code'], 'name' => $item['name'], 'location' => $where, 'cap_ntd' => $cap, 'unit' => $item['unit'], 'copay_ntd' => $own, 'priced' => $priced];
            $rows[] = [$w['subsidy_code'], $item['name'], $where, number_format($cap) . ($priced ? '' : '／10 公分'), $priced ? number_format($own) : '依長度計'];
        }

        $copayTotal = (int) floor($total * $copay / 100);
        $data = [
            'version' => $table['version'], 'items' => $items, 'total_cap_ntd' => $total, 'copay_percent' => $copay,
            'copay_total_ntd' => $copayTotal, 'quota_ntd' => $table['quota']['group1_ntd_3y'],
            'has_per_length_item' => $perUnit, 'source' => $table['source'], 'source_url' => $table['source_url'],
        ];
        $pdo->prepare('DELETE FROM subsidy_packages WHERE scan_id = ?')->execute([$scanId]);
        $pdf = $this->renderPdf($scanId, $home, $table, $rows, $data);
        $pdo->prepare('INSERT INTO subsidy_packages (scan_id, pack_version, items_json, copay_json, pdf_path) VALUES (?, ?, ?, ?, ?)')
            ->execute([$scanId, $table['version'], json_encode($data, JSON_UNESCAPED_UNICODE), json_encode(['percent' => $copay, 'total' => $copayTotal]), $pdf]);
        return $data;
    }

    private function renderPdf(int $scanId, array $home, array $table, array $rows, array $data): string
    {
        $root = Pipeline::root();
        $q = Db::pdo()->prepare('SELECT room, overlay_path FROM frames WHERE scan_id = ? ORDER BY idx');
        $q->execute([$scanId]);
        $evidence = [];
        foreach ($q->fetchAll() as $f) {
            $evidence[] = ['path' => $root . '/' . $f['overlay_path'], 'caption' => $f['room']];
        }
        $mobility = ['independent' => '可自行行走', 'walker' => '使用助行器', 'wheelchair' => '使用輪椅'][$home['mobility']];
        $warnings = ['本文件為申請前草稿，尚待專業人員評估核定，不代表已符合補助資格或核定金額。'];
        if ($home['simulated']) {
            array_unshift($warnings, '【模擬資料 SIMULATED】現場影像由 AI 生成、比例尺為假設值，僅供流程展示。');
        }
        $totals = [
            sprintf('已列品項給付上限合計：%s 元（第一組額度：三年 %s 元）', number_format($data['total_cap_ntd']), number_format($data['quota_ntd'])),
            sprintf('自付試算（第 %d 類，%d%%，以上限計）：%s 元', $home['ltc_category'], $data['copay_percent'], number_format($data['copay_total_ntd'])),
        ];
        if ($data['has_per_length_item']) {
            $totals[] = '依長度計價的品項（固定式扶手）未計入合計，長度待現場評估。';
        }
        $spec = [
            'title' => '長照 2.0 居家無障礙環境改善　申請資料草稿',
            'warnings' => $warnings,
            'applicant' => [['住戶', $home['name']], ['行動方式', $mobility], ['長照需要等級', '第 ' . $home['ltc_level'] . ' 級（申請人自填，待照管中心確認）'], ['長照身分別', '第 ' . $home['ltc_category'] . ' 類（申請人自填）']],
            'items' => $rows,
            'totals' => $totals,
            'evidence' => $evidence,
            'checklist' => [
                '撥打 1966 或聯絡照管中心／A 單位，確認長照需要等級（須第二級至第八級）',
                '由輔具評估人員或專業人員到府評估，核定後才能施工或購買',
                '第一組與第二組給付擇一申請；確認近三年已使用額度',
                '租屋者先取得房東同意',
                '施工後保留前後照片（本系統重拍驗證可產出）',
            ],
            'citations' => [$table['source'], $table['source_url'], $table['eligibility'], $table['quota']['note'], $table['copay_note']],
        ];
        $dir = Pipeline::scanDir($scanId);
        file_put_contents("$dir/pack.json", json_encode($spec, JSON_UNESCAPED_UNICODE));
        SamTool::run('pdf', ['spec' => "$dir/pack.json", 'out' => "$dir/pack.pdf"]);
        return 'storage/scans/' . $scanId . '/pack.pdf';
    }
}
