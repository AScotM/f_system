<?php
class FileSystemAnalyzer {
    private $cache = [];
    private $config = [
        'warning_threshold' => 80,
        'critical_threshold' => 90,
        'timeout' => 30,
        'scan_paths' => []
    ];
    
    private const STATUS_CRITICAL = 'CRITICAL';
    private const STATUS_WARNING = 'WARNING';
    private const STATUS_OK = 'OK';
    
    public function __construct(array $config = []) {
        $this->config = array_merge($this->config, $config);
        $this->setupDefaultPaths();
    }
    
    private function setupDefaultPaths(): void {
        if (strtoupper(PHP_OS_FAMILY) === 'WINDOWS') {
            $drives = [];
            foreach (range('C', 'Z') as $drive) {
                if (is_dir("{$drive}:\\")) {
                    $drives[] = "{$drive}:\\";
                }
            }
            $this->config['scan_paths'] = !empty($drives) ? $drives : ['C:\\'];
        } else {
            $this->config['scan_paths'] = ['/', '/home', '/var', '/tmp', '/usr', '/boot'];
        }
    }
    
    public function getFileSystemInfo(string $path = '/'): array {
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
    
    public function clearCache(?string $path = null): void {
        if ($path) {
            unset($this->cache[$path]);
        } else {
            $this->cache = [];
        }
    }
    
    private function isValidPath(string $path): bool {
        return file_exists($path) && is_readable($path);
    }
    
    private function executeCommand(string $command, string $path): ?string {
        $timeout = escapeshellarg((string)$this->config['timeout']);
        $escapedPath = escapeshellarg($path);
        
        $fullCommand = sprintf('timeout %s %s %s 2>/dev/null', 
            $timeout, 
            $command, 
            $escapedPath
        );
        
        return shell_exec($fullCommand);
    }
    
    private function getFileSystemType(string $path): string {
        $os = strtoupper(PHP_OS_FAMILY);
        
        if ($os === 'LINUX' || $os === 'FREEBSD' || $os === 'DARWIN') {
            $output = $this->executeCommand('df -T', $path);
            
            if ($output) {
                $lines = explode("\n", trim($output));
                if (count($lines) >= 2) {
                    $parts = preg_split('/\s+/', $lines[1]);
                    return $parts[1] ?? 'Unknown';
                }
            }
            
            $output = $this->executeCommand('mount', $path);
            if ($output && preg_match('/type\s+(\S+)/', $output, $matches)) {
                return $matches[1];
            }
            
            $output = $this->executeCommand('df -P', $path);
            if ($output) {
                return 'Unknown (df -T not supported)';
            }
        } elseif ($os === 'WINDOWS') {
            $drive = escapeshellarg(substr($path, 0, 2));
            $output = shell_exec("fsutil fsinfo volumeinfo $drive 2>nul");
            if ($output && preg_match('/File System Name\s*:\s*(\S+)/', $output, $matches)) {
                return $matches[1];
            }
        }
        
        return 'Unknown';
    }
    
    private function getMountPoint(string $path): string {
        if (strtoupper(PHP_OS_FAMILY) === 'WINDOWS') {
            $realPath = realpath($path);
            return $realPath ? substr($realPath, 0, 2) : substr($path, 0, 2);
        }
        
        $output = $this->executeCommand('df -P', $path);
        if ($output) {
            $lines = explode("\n", trim($output));
            if (count($lines) >= 2) {
                $parts = preg_split('/\s+/', $lines[1]);
                return $parts[5] ?? $path;
            }
        }
        
        return $path;
    }
    
    private function getInodeInfo(string $path): ?array {
        if (strtoupper(PHP_OS_FAMILY) === 'WINDOWS') {
            return ['total' => 'N/A', 'used' => 'N/A', 'free' => 'N/A', 'usage_percent' => 'N/A'];
        }
        
        $output = $this->executeCommand('df -i', $path);
        
        if ($output) {
            $lines = explode("\n", trim($output));
            if (count($lines) >= 2) {
                $parts = preg_split('/\s+/', $lines[1]);
                if (count($parts) >= 6) {
                    return [
                        'total' => $parts[1] ?? 0,
                        'used' => $parts[2] ?? 0,
                        'free' => $parts[3] ?? 0,
                        'usage_percent' => isset($parts[4]) ? rtrim($parts[4], '%') : 0
                    ];
                }
            }
        }
        
        return null;
    }
    
    private function getUsageStatus(float $usagePercent): string {
        if ($usagePercent >= $this->config['critical_threshold']) {
            return self::STATUS_CRITICAL;
        } elseif ($usagePercent >= $this->config['warning_threshold']) {
            return self::STATUS_WARNING;
        } else {
            return self::STATUS_OK;
        }
    }
    
    public function getAllFileSystems(): array {
        $results = [];
        foreach ($this->config['scan_paths'] as $path) {
            $results[$path] = $this->getFileSystemInfo($path);
        }
        return $results;
    }
    
    public function getUniqueMountPoints(): array {
        $allInfo = $this->getAllFileSystems();
        $uniqueMounts = [];
        
        foreach ($allInfo as $info) {
            if (!isset($info['error']) && !isset($uniqueMounts[$info['mount_point']])) {
                $uniqueMounts[$info['mount_point']] = $info;
            }
        }
        
        return $uniqueMounts;
    }
    
    public function formatBytes(float $bytes, int $precision = 2): string {
        if ($bytes <= 0) return '0 B';
        
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
        $base = log($bytes) / log(1024);
        $pow = min((int)floor($base), count($units) - 1);
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
    
    public function generateTextReport(): string {
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
    
    private function formatFileSystemInfo(array $info): string {
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
            $inodePercent = is_numeric($inodes['usage_percent']) ? $inodes['usage_percent'] . '%' : $inodes['usage_percent'];
            $output .= "Inodes: {$inodes['used']}/{$inodes['total']} ($inodePercent used)\n";
        }
        
        $barWidth = 40;
        $usedBars = round(($info['usage_percent'] / 100) * $barWidth);
        $output .= "[" . str_repeat("█", $usedBars) . str_repeat("░", $barWidth - $usedBars) . "]\n\n";
        
        return $output;
    }
    
    private function generateSummaryTable(array $uniqueMounts): string {
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
                $inodeInfo = is_numeric($inodes['usage_percent']) ? $inodes['usage_percent'] . '%' : $inodes['usage_percent'];
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
    
    public function getSystemSummary(): array {
        $uniqueMounts = $this->getUniqueMountPoints();
        $totalSpace = 0;
        $totalUsed = 0;
        $criticalCount = 0;
        $warningCount = 0;
        
        foreach ($uniqueMounts as $info) {
            if (!isset($info['error'])) {
                $totalSpace += $info['total'];
                $totalUsed += $info['used'];
                
                if ($info['status'] === self::STATUS_CRITICAL) $criticalCount++;
                if ($info['status'] === self::STATUS_WARNING) $warningCount++;
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
