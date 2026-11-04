<?php

declare(strict_types=1);

namespace SafePath\Http;

use SafePath\Job\Pipeline;
use SafePath\Scans;

/** Server-rendered pages and evidence images. */
final class Web
{
    public static function home(): void
    {
        $demos = Scans::rows('SELECT s.id, s.status, h.name, h.mobility FROM scans s JOIN households h ON h.id = s.household_id WHERE s.is_demo = 1 AND s.parent_scan_id IS NULL ORDER BY s.id', []);
        $recent = Access::judgeMode() ? [] : Scans::rows('SELECT s.id, s.status, s.created_at, h.name FROM scans s JOIN households h ON h.id = s.household_id WHERE s.is_demo = 0 AND s.parent_scan_id IS NULL ORDER BY s.id DESC LIMIT 10', []);
        self::render('home', compact('demos', 'recent'));
    }

    public static function newScan(): void
    {
        if (!Access::liveUploads()) {
            header('Location: ' . Url::to('/'));
            return;
        }
        self::render('new', []);
    }

    public static function scan(array $p): void
    {
        $report = Scans::report((int) $p['id']);
        if ($report === null || !Access::canView($report['scan'])) {
            http_response_code(404);
            echo I18n::t('not_found');
            return;
        }
        $verify = null;
        if ($report['verify_scan'] !== null) {
            $verify = Scans::report((int) $report['verify_scan']['id']);
        }
        self::render('scan', compact('report', 'verify'));
    }

    /** Only files under one scan's own folder, and only image types. */
    public static function media(array $p): void
    {
        $rel = (string) ($_GET['f'] ?? '');
        if (!preg_match('#^det/\d+/(overlay|blurred)\.jpg$#', $rel)) {
            http_response_code(404);
            return;
        }
        $file = Pipeline::scanDir((int) $p['id']) . '/' . $rel;
        $scan = Scans::get((int) $p['id']);
        if ($scan === null || !Access::canView($scan) || !is_file($file)) {
            http_response_code(404);
            return;
        }
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=3600');
        readfile($file);
    }

    public static function asset(array $p): void
    {
        $file = Pipeline::root() . '/public/assets/' . $p['name'];
        if (!preg_match('#^[a-z]+\.png$#', $p['name']) || !is_file($file)) {
            http_response_code(404);
            return;
        }
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=86400');
        readfile($file);
    }

    private static function render(string $template, array $vars): void
    {
        extract($vars);
        $root = Pipeline::root();
        $body = $root . '/templates/' . $template . '.php';
        require $root . '/templates/layout.php';
    }
}
