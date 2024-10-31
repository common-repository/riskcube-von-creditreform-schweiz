<?php

namespace Cube\Helper;

use Exception;

class FileHelper
{
    const LOG_DIR = WP_CONTENT_DIR . '/uploads/riskcube/';

    /**
     * Get the sha1 hash value of an input file, or if it cannot be found return the plugin version instead
     * @param string $file_name Path of File, relative to Plugin directory
     * @return string
     */
    public static function get_file_version(string $file_name): string
    {
        $version = sha1_file(RISKCUBE__PLUGIN_DIR . $file_name);
        if (!$version) {
            return get_plugin_data(RISKCUBE__PLUGIN_DIR . 'riskcube.php')['Version'];
        }

        return $version;
    }

    public static function get_log_file(): string
    {
        return self::LOG_DIR . date('Y-m-d') . '.txt';
    }

    public static function getTransactionHistoryFile(): string
    {
        return self::LOG_DIR . 'transactionHistory.json';
    }

    /**
     * Read directory and return list of all CSV files with absolute path
     * @param string $fileDir
     * @return array
     */
    public static function getCsvFiles(string $fileDir): array
    {
        if (!is_dir($fileDir)) {
            return [];
        }

        try {
            $files = [];
            foreach (scandir($fileDir) as $file) {
                if ('csv' === pathinfo($fileDir . $file)['extension']) {
                    $files[] = $fileDir . $file;
                }
            }

            return $files;
        } catch (Exception $exception) {
            return [];
        }
    }

    /**
     * Remove a file if it exists
     * @param string $absolutPathToFile
     * @return void
     */
    public static function deleteFile(string $absolutPathToFile): void
    {
        if (file_exists($absolutPathToFile)) {
            unlink($absolutPathToFile);
        }
    }
}