<?php

namespace Cube\Helper;

class Logger
{
    const LOG_DIR = WP_CONTENT_DIR . '/uploads/riskcube/';

    public static function createDirIfNotExists(): void
    {
        if (!is_dir(FileHelper::LOG_DIR)) {
            mkdir(FileHelper::LOG_DIR);
        }
    }

    public static function logDev($msg, $data = null)
    {
        return;

        $fl = fopen(self::LOG_DIR . 'dev.txt', 'a');
        $str = date('Y-m-d H:i:s ') . ' ' . $msg . ' ' . (!is_null($data) ? json_encode($data) : '') . PHP_EOL;
        fwrite($fl, $str);
        fclose($fl);
    }

    public static function logw($msg, $extra = []): void
    {
        self::createDirIfNotExists();

        $fl = fopen(FileHelper::get_log_file(), 'a');
        $str = date('Y-m-d H:i:s ') . $msg . PHP_EOL;
        if (is_array($extra) && count($extra)) {
            foreach ($extra as $y => $x) {
                $str .= '>>' . $y . ': ' . (is_array($x) ? print_r($x, true) : $x) . PHP_EOL;
            }
        }
        fwrite($fl, $str);
        fclose($fl);
    }
}