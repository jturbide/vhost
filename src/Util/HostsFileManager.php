<?php

namespace Vhost\Util;

class HostsFileManager
{
    public static function updateHosts(array $entries, string $hostsFile, bool $backup = true): bool
    {
        if ($backup && file_exists($hostsFile)) {
            $backupPath = $hostsFile . '.bak-' . date('YmdHis');
            if (!copy($hostsFile, $backupPath)) {
                return false;
            }
        }
        
        $content = file_exists($hostsFile) ? file_get_contents($hostsFile) : '';
        
        $begin = '# BEGIN VHOST';
        $end = '# END VHOST';
        
        $pattern = "/{$begin}[\s\S]*?{$end}/";
        
        $newBlock = $begin . PHP_EOL . implode(PHP_EOL, $entries) . PHP_EOL . $end;
        
        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, $newBlock, $content);
        } else {
            $content .= PHP_EOL . PHP_EOL . $newBlock;
        }
        
        return (bool)file_put_contents($hostsFile, $content);
    }
}
