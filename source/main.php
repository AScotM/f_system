<?php
class FileSystemAnalyzer {
    private $cache = [];
    private $config = [
        'warning_threshold' => 80,
        'critical_threshold' => 90,
        'timeout' => 30,
        'scan_paths' => []
    ];
    
    public function __construct($config = []) {
        $this->config = array_merge($this->config, $config);
        $this->setupDefaultPaths();
    }
    
    private function setupDefaultPaths() {
        if (strtoupper(PHP_OS_FAMILY) === 'WINDOWS') {
            $this->config['scan_paths'] = ['C:\\', 'D:\\', 'E:\\'];
        } else {
            $this->config['scan_paths'] = ['/', '/home', '/var', '/tmp', '/usr', '/boot'];
        }
    }
    
    public function getFileSystemInfo($path = '/') {
        if (isset($this->cache[$path])) {
            return $this->cache[$path];
        }
        
        $result = [
            'path' => $path,
            'total' => 0,
            'free' => 0,
            'used' => 0,
            'usage_percent' => 0,
            'file_system' => 'Unknown',
            'mount_point' => '',
            'inodes' => null
        ];
        
        if (!$this->isValidPath($path)) {
            $result['error'] = "Invalid or inaccessible path: $path";
            return $result;
        }
        
        $total = disk_total_space($path);
        $free = disk_free_space($path);
        
        if ($total === false || $free === false) {
            $result['error'] = "Could not retrieve disk space";
            return $result;
        }
        
        $used = $total - $free;
        $usagePercent = ($total > 0) ? round(($used / $total) * 100, 2) : 0;
        
        $fileSystemType = $this->getFileSystemType($path);
        $mountPoint = $this->getMountPoint($path);
        $inodeInfo = $this->getInodeInfo($path);
        
        $result = array_merge($result, [
            'total' => $total,
            'free' => $free,
            'used' => $used,
            'usage_percent' => $usagePercent,
            'file_system' => $fileSystemType,
            'mount_point' => $mountPoint,
            'inodes' => $inodeInfo,
            'status' => $this->getUsageStatus($usagePercent)
        ]);
        
        $this->cache[$path] = $result;
        return $result;
    }
    
    private function isValidPath($path) {
        return file_exists($path) && is_readable($path) && is_dir($path);
    }
    
    private function getFileSystemType($path) {
        $os = strtoupper(PHP_OS_FAMILY);
        
        if ($os === 'LINUX' || $os === 'FREEBSD' || $os === 'DARWIN') {
            $command = "timeout {$this->config['timeout']} df -T " . escapeshellarg($path) . " 2>/dev/null";
            $output = shell_exec($command);
            
            if ($output) {
                $lines = explode("\n", trim($output));
                if (count($lines) >= 2) {
                    $parts = preg_split('/\s+/', $lines[1]);
                    return $parts[1] ?? 'Unknown';
                }
            }
            
            $command = "timeout {$this->config['timeout']} mount | grep " . escapeshellarg($path);
            $output = shell_exec($command);
            if ($output && preg_match('/type\s+(\S+)/', $output, $matches)) {
                return $matches[1];
            }
        } elseif ($os === 'WINDOWS') {
            $drive = substr($path, 0, 2);
            $output = shell_exec("fsutil fsinfo volumeinfo $drive 2>nul");
            if ($output && preg_match('/File System Name\s*:\s*(\S+)/', $output, $matches)) {
                return $matches[1];
            }
        }
        
        return 'Unknown';
    }
    
    private function getMountPoint($path) {
        if (strtoupper(PHP_OS_FAMILY) === 'WINDOWS') {
            return substr($path, 0, 2);
        }
        
        $command = "timeout {$this->config['timeout']} df " . escapeshellarg($path) . " 2>/dev/null | tail -1 | awk '{print \$6}'";
        $output = shell_exec($command);
        return $output ? trim($output) : $path;
    }
    
    private function getInodeInfo($path) {
        if (strtoupper(PHP_OS_FAMILY) === 'WINDOWS') {
            return null;
        }
        
        $command = "timeout {$this->config['timeout']} df -i " . escapeshellarg($path) . " 2>/dev/null | tail -1";
        $output = shell_exec($command);
        
        if ($output) {
            $parts = preg_split('/\s+/', trim($output));
            if (count($parts) >= 6) {
                return [
                    'total' => $parts[1] ?? 0,
                    'used' => $parts[2] ?? 0,
                    'free' => $parts[3] ?? 0,
                    'usage_percent' => isset($parts[4]) ? rtrim($parts[4], '%') : 0
                ];
            }
        }
        
        return null;
    }
    
    private function getUsageStatus($usagePercent) {
        if ($usagePercent >= $this->config['critical_threshold']) {
            return 'CRITICAL';
        } elseif ($usagePercent >= $this->config['warning_threshold']) {
            return 'WARNING';
        } else {
            return 'OK';
        }
    }
    
    public function getAllFileSystems() {
        $results = [];
        foreach ($this->config['scan_paths'] as $path) {
            $results[$path] = $this->getFileSystemInfo($path);
        }
        return $results;
    }
    
    public function getUniqueMountPoints() {
        $allInfo = $this->getAllFileSystems();
        $uniqueMounts = [];
        
        foreach ($allInfo as $info) {
            if (!isset($info['error']) && !isset($uniqueMounts[$info['mount_point']])) {
                $uniqueMounts[$info['mount_point']] = $info;
            }
        }
        
        return $uniqueMounts;
    }
    
    public function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        
        if ($bytes <= 0) return '0 B';
        
        $base = log($bytes) / log(1024);
        $pow = min(floor($base), count($units) - 1);
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
    
    public function generateTextReport() {
        $uniqueMounts = $this->getUniqueMountPoints();
        $report = "";
        
        $report .= "FILE SYSTEM ANALYSIS REPORT\n";
        $report .= "Generated: " . date('Y-m-d H:i:s') . "\n";
        $report .= str_repeat("=", 100) . "\n\n";
        
        foreach ($uniqueMounts as $mount => $info) {
            $report .= $this->formatFileSystemInfo($info);
        }
        
        $report .= $this->generateSummaryTable($uniqueMounts);
        
        return $report;
    }
    
    private function formatFileSystemInfo($info) {
        $output = "";
        
        if (isset($info['error'])) {
            $output .= "PATH: {$info['path']} - ERROR: {$info['error']}\n";
            return $output;
        }
        
        $output .= "MOUNT: {$info['mount_point']}\n";
        $output .= "Original Path: {$info['path']}\n";
        $output .= "Filesystem: {$info['file_system']}\n";
        $output .= "Size: " . $this->formatBytes($info['total']) . "\n";
        $output .= "Used: " . $this->formatBytes($info['used']) . "\n";
        $output .= "Available: " . $this->formatBytes($info['free']) . "\n";
        $output .= "Usage: {$info['usage_percent']}% [{$info['status']}]\n";
        
        if ($info['inodes']) {
            $inodes = $info['inodes'];
            $output .= "Inodes: {$inodes['used']}/{$inodes['total']} ({$inodes['usage_percent']}% used)\n";
        }
        
        $barWidth = 40;
        $usedBars = round(($info['usage_percent'] / 100) * $barWidth);
        $output .= "[" . str_repeat("█", $usedBars) . str_repeat("░", $barWidth - $usedBars) . "]\n\n";
        
        return $output;
    }
    
    private function generateSummaryTable($uniqueMounts) {
        $output = "SUMMARY TABLE (Unique Mount Points)\n";
        $output .= str_repeat("-", 110) . "\n";
        $output .= sprintf("%-12s %-10s %-12s %-12s %-12s %-8s %-10s %s\n", 
            "Mount", "FS Type", "Size", "Used", "Available", "Use%", "Status", "Inodes");
        $output .= str_repeat("-", 110) . "\n";
        
        foreach ($uniqueMounts as $mount => $info) {
            if (isset($info['error'])) {
                $output .= sprintf("%-12s %-60s\n", $info['path'], "ERROR: " . $info['error']);
                continue;
            }
            
            $inodeInfo = "N/A";
            if ($info['inodes']) {
                $inodes = $info['inodes'];
                $inodeInfo = "{$inodes['usage_percent']}%";
            }
            
            $output .= sprintf("%-12s %-10s %-12s %-12s %-12s %-8s %-10s %s\n",
                $info['mount_point'],
                substr($info['file_system'], 0, 10),
                $this->formatBytes($info['total']),
                $this->formatBytes($info['used']),
                $this->formatBytes($info['free']),
                $info['usage_percent'] . '%',
                $info['status'],
                $inodeInfo
            );
        }
        
        $output .= str_repeat("-", 110) . "\n";
        return $output;
    }
    
    public function getSystemSummary() {
        $uniqueMounts = $this->getUniqueMountPoints();
        $totalSpace = 0;
        $totalUsed = 0;
        $criticalCount = 0;
        $warningCount = 0;
        
        foreach ($uniqueMounts as $info) {
            if (!isset($info['error'])) {
                $totalSpace += $info['total'];
                $totalUsed += $info['used'];
                
                if ($info['status'] === 'CRITICAL') $criticalCount++;
                if ($info['status'] === 'WARNING') $warningCount++;
            }
        }
        
        return [
            'total_filesystems' => count($uniqueMounts),
            'total_space' => $totalSpace,
            'total_used' => $totalUsed,
            'total_usage_percent' => $totalSpace > 0 ? round(($totalUsed / $totalSpace) * 100, 2) : 0,
            'critical_count' => $criticalCount,
            'warning_count' => $warningCount,
            'ok_count' => count($uniqueMounts) - $criticalCount - $warningCount
        ];
    }
}

$analyzer = new FileSystemAnalyzer([
    'warning_threshold' => 80,
    'critical_threshold' => 90
]);

echo $analyzer->generateTextReport();

$summary = $analyzer->getSystemSummary();
echo "\nSYSTEM SUMMARY:\n";
echo "Unique Mount Points: {$summary['total_filesystems']}\n";
echo "Total Space: " . $analyzer->formatBytes($summary['total_space']) . "\n";
echo "Total Used: " . $analyzer->formatBytes($summary['total_used']) . "\n";
echo "Overall Usage: {$summary['total_usage_percent']}%\n";
echo "Status - Critical: {$summary['critical_count']}, Warning: {$summary['warning_count']}, OK: {$summary['ok_count']}\n";

$mountPoints = $analyzer->getUniqueMountPoints();
echo "\nDetected Mount Points: " . implode(', ', array_keys($mountPoints)) . "\n";
?>
