<?php
class FileSystemAnalyzer {
    private $cache = [];
    private $cacheTTL = 300;
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
        $this->validateConfig();
        $this->setupDefaultPaths();
    }
    
    private function validateConfig(): void {
        if ($this->config['warning_threshold'] >= $this->config['critical_threshold']) {
            throw new InvalidArgumentException('Warning threshold must be less than critical threshold');
        }
        
        if ($this->config['timeout'] < 1 || $this->config['timeout'] > 300) {
            throw new InvalidArgumentException('Timeout must be between 1 and 300 seconds');
        }
        
        if ($this->config['warning_threshold'] < 0 || $this->config['warning_threshold'] > 100) {
            throw new InvalidArgumentException('Warning threshold must be between 0 and 100');
        }
        
        if ($this->config['critical_threshold'] < 0 || $this->config['critical_threshold'] > 100) {
            throw new InvalidArgumentException('Critical threshold must be between 0 and 100');
        }
    }
    
    private function setupDefaultPaths(): void {
        if (strtoupper(PHP_OS_FAMILY) === 'WINDOWS') {
            $drives = [];
            foreach (range('C', 'Z') as $drive) {
                $drivePath = "{$drive}:\\";
                if (is_dir($drivePath)) {
                    $drives[] = $drivePath;
                }
            }
            $this->config['scan_paths'] = !empty($drives) ? $drives : ['C:\\'];
        } else {
            $this->config['scan_paths'] = ['/', '/home', '/var', '/tmp', '/usr', '/boot'];
        }
    }
    
    public function getFileSystemInfo(string $path = '/'): array {
        if (isset($this->cache[$path]) && (time() - $this->cache[$path]['timestamp']) < $this->cacheTTL) {
            return $this->cache[$path]['data'];
        }
        
        try {
            $this->isValidPath($path);
        } catch (RuntimeException $e) {
            return [
                'path' => $path,
                'error' => $e->getMessage()
            ];
        }
        
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        
        if ($total === false || $free === false) {
            return [
                'path' => $path,
                'error' => 'Could not retrieve disk space information'
            ];
        }
        
        $used = $total - $free;
        $usagePercent = ($total > 0) ? round(($used / $total) * 100, 2) : 0;
        
        $fileSystemType = $this->getFileSystemType($path);
        $mountPoint = $this->getMountPoint($path);
        $inodeInfo = $this->getInodeInfo($path);
        
        $result = [
            'path' => $path,
            'total' => $total,
            'free' => $free,
            'used' => $used,
            'usage_percent' => $usagePercent,
            'file_system' => $fileSystemType,
            'mount_point' => $mountPoint,
            'inodes' => $inodeInfo,
            'status' => $this->getUsageStatus($usagePercent)
        ];
        
        $this->cache[$path] = [
            'data' => $result,
            'timestamp' => time()
        ];
        
        return $result;
    }
    
    public function clearCache(?string $path = null): void {
        if ($path !== null) {
            unset($this->cache[$path]);
        } else {
            $this->cache = [];
        }
    }
    
    public function setCacheTTL(int $seconds): void {
        if ($seconds < 1) {
            throw new InvalidArgumentException('Cache TTL must be at least 1 second');
        }
        $this->cacheTTL = $seconds;
    }
    
    private function isValidPath(string $path): bool {
        if (!file_exists($path)) {
            throw new RuntimeException("Path does not exist: $path");
        }
        if (!is_readable($path)) {
            throw new RuntimeException("Path is not readable: $path");
        }
        return true;
    }
    
    private function executeCommand(string $command, string $path): ?string {
        $escapedPath = escapeshellarg($path);
        $escapedCommand = escapeshellcmd($command);
        
        if (strtoupper(PHP_OS_FAMILY) === 'WINDOWS') {
            $fullCommand = sprintf('%s %s 2>nul', $escapedCommand, $escapedPath);
        } else {
            $fullCommand = sprintf('timeout %d %s %s 2>/dev/null', 
                $this->config['timeout'], 
                $escapedCommand, 
                $escapedPath
            );
        }
        
        $output = shell_exec($fullCommand);
        return $output !== null ? $output : null;
    }
    
    private function getFileSystemType(string $path): string {
        $os = strtoupper(PHP_OS_FAMILY);
        
        if ($os === 'LINUX' || $os === 'FREEBSD' || $os === 'DARWIN') {
            $output = $this->executeCommand('df -T', $path);
            
            if ($output) {
                $lines = explode("\n", trim($output));
                foreach ($lines as $line) {
                    if (strpos($line, $path) !== false || strpos($line, 'Filesystem') === false) {
                        $parts = preg_split('/\s+/', $line);
                        if (count($parts) >= 2) {
                            return $parts[1] ?? 'Unknown';
                        }
                    }
                }
            }
            
            $output = $this->executeCommand('mount', $path);
            if ($output && preg_match('/type\s+(\S+)/', $output, $matches)) {
                return $matches[1];
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
            foreach ($lines as $line) {
                if (strpos($line, $path) !== false || strpos($line, 'Filesystem') === false) {
                    $parts = preg_split('/\s+/', $line);
                    if (count($parts) >= 6) {
                        return $parts[5] ?? $path;
                    }
                }
            }
        }
        
        return $path;
    }
    
    private function getInodeInfo(string $path): ?array {
        if (strtoupper(PHP_OS_FAMILY) === 'WINDOWS') {
            return null;
        }
        
        $output = $this->executeCommand('df -i', $path);
        
        if ($output) {
            $lines = explode("\n", trim($output));
            foreach ($lines as $line) {
                if (strpos($line, $path) !== false) {
                    $parts = preg_split('/\s+/', $line);
                    if (count($parts) >= 6 && is_numeric($parts[1] ?? '')) {
                        return [
                            'total' => (int)($parts[1] ?? 0),
                            'used' => (int)($parts[2] ?? 0),
                            'free' => (int)($parts[3] ?? 0),
                            'usage_percent' => isset($parts[4]) ? (int)rtrim($parts[4], '%') : 0
                        ];
                    }
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
        
        foreach ($allInfo as $path => $info) {
            if (!isset($info['error']) && !isset($uniqueMounts[$info['mount_point']])) {
                $uniqueMounts[$info['mount_point']] = $info;
            }
        }
        
        return $uniqueMounts;
    }
    
    public function getFileSystemsByStatus(string $status): array {
        $results = [];
        foreach ($this->getAllFileSystems() as $path => $info) {
            if (($info['status'] ?? '') === $status) {
                $results[$path] = $info;
            }
        }
        return $results;
    }
    
    public function formatBytes(float $bytes, int $precision = 2): string {
        if ($bytes <= 0) return '0 B';
        
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
        $pow = 0;
        $value = $bytes;
        
        while ($value >= 1024 && $pow < count($units) - 1) {
            $value /= 1024;
            $pow++;
        }
        
        return round($value, $precision) . ' ' . $units[$pow];
    }
    
    public function generateTextReport(): string {
        $uniqueMounts = $this->getUniqueMountPoints();
        $reportLines = [];
        
        $reportLines[] = "FILE SYSTEM ANALYSIS REPORT";
        $reportLines[] = "Generated: " . date('Y-m-d H:i:s');
        $reportLines[] = str_repeat("=", 100);
        $reportLines[] = "";
        
        foreach ($uniqueMounts as $mount => $info) {
            $reportLines[] = $this->formatFileSystemInfo($info);
        }
        
        $reportLines[] = $this->generateSummaryTable($uniqueMounts);
        
        return implode("\n", $reportLines);
    }
    
    private function formatFileSystemInfo(array $info): string {
        $lines = [];
        
        if (isset($info['error'])) {
            $lines[] = "PATH: {$info['path']} - ERROR: {$info['error']}";
            return implode("\n", $lines);
        }
        
        $lines[] = "MOUNT: {$info['mount_point']}";
        $lines[] = "Original Path: {$info['path']}";
        $lines[] = "Filesystem: {$info['file_system']}";
        $lines[] = "Size: " . $this->formatBytes($info['total']);
        $lines[] = "Used: " . $this->formatBytes($info['used']);
        $lines[] = "Available: " . $this->formatBytes($info['free']);
        $lines[] = "Usage: {$info['usage_percent']}% [{$info['status']}]";
        
        if ($info['inodes'] !== null) {
            $inodes = $info['inodes'];
            $inodePercent = $inodes['usage_percent'] . '%';
            $lines[] = "Inodes: {$inodes['used']}/{$inodes['total']} ($inodePercent used)";
        }
        
        $barWidth = 40;
        $usedBars = (int)round(($info['usage_percent'] / 100) * $barWidth);
        $usedBars = min($usedBars, $barWidth);
        $lines[] = "[" . str_repeat("█", $usedBars) . str_repeat("░", $barWidth - $usedBars) . "]";
        $lines[] = "";
        
        return implode("\n", $lines);
    }
    
    private function generateSummaryTable(array $uniqueMounts): string {
        $lines = [];
        
        $lines[] = "SUMMARY TABLE (Unique Mount Points)";
        $lines[] = str_repeat("-", 110);
        $lines[] = sprintf("%-12s %-10s %-12s %-12s %-12s %-8s %-10s %s", 
            "Mount", "FS Type", "Size", "Used", "Available", "Use%", "Status", "Inodes");
        $lines[] = str_repeat("-", 110);
        
        foreach ($uniqueMounts as $mount => $info) {
            if (isset($info['error'])) {
                $lines[] = sprintf("%-12s %-60s", $info['path'], "ERROR: " . $info['error']);
                continue;
            }
            
            $inodeInfo = "N/A";
            if ($info['inodes'] !== null) {
                $inodeInfo = $info['inodes']['usage_percent'] . '%';
            }
            
            $lines[] = sprintf("%-12s %-10s %-12s %-12s %-12s %-8s %-10s %s",
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
        
        $lines[] = str_repeat("-", 110);
        
        return implode("\n", $lines);
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
    
    public function toJson(): string {
        return json_encode([
            'timestamp' => date('c'),
            'config' => $this->config,
            'filesystems' => $this->getUniqueMountPoints(),
            'summary' => $this->getSystemSummary()
        ], JSON_PRETTY_PRINT);
    }
    
    public function toArray(): array {
        return [
            'timestamp' => date('c'),
            'config' => $this->config,
            'filesystems' => $this->getUniqueMountPoints(),
            'summary' => $this->getSystemSummary()
        ];
    }
}

try {
    $analyzer = new FileSystemAnalyzer([
        'warning_threshold' => 80,
        'critical_threshold' => 90,
        'timeout' => 30
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
    
} catch (InvalidArgumentException $e) {
    echo "Configuration error: " . $e->getMessage() . "\n";
    exit(1);
} catch (RuntimeException $e) {
    echo "Runtime error: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "Unexpected error: " . $e->getMessage() . "\n";
    exit(1);
}
