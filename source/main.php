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
    private const STATUS_UNKNOWN = 'UNKNOWN';
    
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
            $mountPoints = $this->getLinuxMountPoints();
            if (!empty($mountPoints)) {
                $this->config['scan_paths'] = $mountPoints;
            } else {
                $this->config['scan_paths'] = ['/', '/home', '/var', '/tmp', '/usr', '/boot'];
            }
        }
    }
    
    private function getLinuxMountPoints(): array {
        $mountPoints = [];
        
        if (file_exists('/proc/mounts')) {
            $content = @file_get_contents('/proc/mounts');
            if ($content) {
                $lines = explode("\n", trim($content));
                foreach ($lines as $line) {
                    $parts = preg_split('/\s+/', $line);
                    if (count($parts) >= 2 && isset($parts[1])) {
                        if ($this->isValidMountPoint($parts[1])) {
                            $mountPoints[] = $parts[1];
                        }
                    }
                }
            }
        } else {
            $output = $this->executeCommand('mount', '-l');
            if ($output) {
                $lines = explode("\n", trim($output));
                foreach ($lines as $line) {
                    if (preg_match('/\son\s([^\s]+)\s/', $line, $matches)) {
                        if ($this->isValidMountPoint($matches[1])) {
                            $mountPoints[] = $matches[1];
                        }
                    }
                }
            }
        }
        
        return array_unique($mountPoints);
    }
    
    private function isValidMountPoint(string $path): bool {
        $excluded = ['/proc', '/sys', '/dev', '/run', '/var/run'];
        foreach ($excluded as $exclude) {
            if (strpos($path, $exclude) === 0) {
                return false;
            }
        }
        return is_dir($path) && is_readable($path);
    }
    
    public function getFileSystemInfo(string $path = '/'): array {
        if (isset($this->cache[$path]) && (time() - $this->cache[$path]['timestamp']) < $this->cacheTTL) {
            return $this->cache[$path]['data'];
        }
        
        try {
            $this->isValidPath($path);
        } catch (RuntimeException $e) {
            return $this->createErrorResponse($path, $e->getMessage());
        }
        
        set_time_limit($this->config['timeout']);
        
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        
        if ($total === false || $free === false) {
            return $this->createErrorResponse($path, 'Could not retrieve disk space information');
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
            'status' => $this->getUsageStatus($usagePercent),
            'timestamp' => time()
        ];
        
        $this->cache[$path] = [
            'data' => $result,
            'timestamp' => time()
        ];
        
        return $result;
    }
    
    private function createErrorResponse(string $path, string $error): array {
        return [
            'path' => $path,
            'error' => $error,
            'status' => self::STATUS_UNKNOWN,
            'timestamp' => time()
        ];
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
    
    private function executeCommand(string $command, string $arguments = ''): ?string {
        if (strtoupper(PHP_OS_FAMILY) === 'WINDOWS') {
            $fullCommand = sprintf('%s %s 2>nul', $command, $arguments);
        } else {
            if ($command === 'timeout') {
                return null;
            }
            $fullCommand = sprintf('%s %s 2>/dev/null', $command, $arguments);
        }
        
        $output = shell_exec($fullCommand);
        return $output !== null ? $output : null;
    }
    
    private function getFileSystemType(string $path): string {
        $os = strtoupper(PHP_OS_FAMILY);
        
        if ($os === 'LINUX' || $os === 'FREEBSD' || $os === 'DARWIN') {
            if (function_exists('shell_exec')) {
                $dfOutput = $this->executeCommand('df -T', escapeshellarg($path));
                
                if ($dfOutput) {
                    $lines = explode("\n", trim($dfOutput));
                    foreach ($lines as $line) {
                        if (strpos($line, $path) !== false) {
                            $parts = preg_split('/\s+/', $line);
                            if (count($parts) >= 7) {
                                return $parts[1] ?? 'Unknown';
                            }
                        }
                    }
                }
                
                $mountOutput = $this->executeCommand('mount', '');
                if ($mountOutput) {
                    $escapedPath = preg_quote($path, '/');
                    if (preg_match('/on ' . $escapedPath . ' type ([^\s]+)/', $mountOutput, $matches)) {
                        return $matches[1];
                    }
                }
            }
            
            if (file_exists('/proc/mounts')) {
                $content = @file_get_contents('/proc/mounts');
                if ($content) {
                    $lines = explode("\n", trim($content));
                    foreach ($lines as $line) {
                        $parts = preg_split('/\s+/', $line);
                        if (count($parts) >= 3 && $parts[1] === $path) {
                            return $parts[2];
                        }
                    }
                }
            }
            
        } elseif ($os === 'WINDOWS') {
            $drive = substr($path, 0, 2);
            if (function_exists('shell_exec')) {
                $output = shell_exec("fsutil fsinfo volumeinfo " . escapeshellarg($drive) . " 2>nul");
                if ($output && preg_match('/File System Name\s*:\s*(\S+)/', $output, $matches)) {
                    return $matches[1];
                }
            }
            
            if (function_exists('exec')) {
                exec('wmic logicaldisk where DeviceID="' . $drive . '" get FileSystem', $output);
                if (isset($output[1]) && trim($output[1])) {
                    return trim($output[1]);
                }
            }
        }
        
        return 'Unknown';
    }
    
    private function getMountPoint(string $path): string {
        if (strtoupper(PHP_OS_FAMILY) === 'WINDOWS') {
            $realPath = realpath($path);
            return $realPath ? substr($realPath, 0, 2) . '\\' : substr($path, 0, 2) . '\\';
        }
        
        $path = realpath($path) ?: $path;
        
        if (function_exists('shell_exec')) {
            $output = $this->executeCommand('df -P', escapeshellarg($path));
            if ($output) {
                $lines = explode("\n", trim($output));
                foreach ($lines as $line) {
                    if (strpos($line, '/') !== false && strpos($line, 'Filesystem') === false) {
                        $parts = preg_split('/\s+/', $line);
                        if (count($parts) >= 6) {
                            return $parts[5] ?? $path;
                        }
                    }
                }
            }
        }
        
        if (file_exists('/proc/mounts')) {
            $content = @file_get_contents('/proc/mounts');
            if ($content) {
                $lines = explode("\n", trim($content));
                $bestMatch = $path;
                $bestLength = 0;
                
                foreach ($lines as $line) {
                    $parts = preg_split('/\s+/', $line);
                    if (count($parts) >= 2) {
                        $mountPoint = $parts[1];
                        if (strpos($path, $mountPoint) === 0) {
                            $length = strlen($mountPoint);
                            if ($length > $bestLength) {
                                $bestLength = $length;
                                $bestMatch = $mountPoint;
                            }
                        }
                    }
                }
                
                return $bestMatch;
            }
        }
        
        return $path;
    }
    
    private function getInodeInfo(string $path): ?array {
        if (strtoupper(PHP_OS_FAMILY) === 'WINDOWS') {
            return null;
        }
        
        if (function_exists('shell_exec')) {
            $output = $this->executeCommand('df -i', escapeshellarg($path));
            
            if ($output) {
                $lines = explode("\n", trim($output));
                foreach ($lines as $line) {
                    if (strpos($line, $path) !== false && strpos($line, 'Filesystem') === false) {
                        $parts = preg_split('/\s+/', $line);
                        $filteredParts = array_values(array_filter($parts, function($part) {
                            return $part !== '';
                        }));
                        
                        if (count($filteredParts) >= 5) {
                            $total = (int)str_replace(',', '', $filteredParts[1] ?? 0);
                            $used = (int)str_replace(',', '', $filteredParts[2] ?? 0);
                            $free = (int)str_replace(',', '', $filteredParts[3] ?? 0);
                            $usagePercent = isset($filteredParts[4]) ? (int)rtrim($filteredParts[4], '%') : 0;
                            
                            return [
                                'total' => $total,
                                'used' => $used,
                                'free' => $free,
                                'usage_percent' => $usagePercent
                            ];
                        }
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
            $info = $this->getFileSystemInfo($path);
            $results[$path] = $info;
        }
        return $results;
    }
    
    public function getUniqueMountPoints(): array {
        $allInfo = $this->getAllFileSystems();
        $uniqueMounts = [];
        
        foreach ($allInfo as $path => $info) {
            if (!isset($info['error']) && isset($info['mount_point']) && !isset($uniqueMounts[$info['mount_point']])) {
                $uniqueMounts[$info['mount_point']] = $info;
            }
        }
        
        ksort($uniqueMounts);
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
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
    
    public function generateTextReport(): string {
        $uniqueMounts = $this->getUniqueMountPoints();
        $reportLines = [];
        
        $reportLines[] = "FILE SYSTEM ANALYSIS REPORT";
        $reportLines[] = "Generated: " . date('Y-m-d H:i:s');
        $reportLines[] = "System: " . PHP_OS_FAMILY . ' ' . php_uname('r');
        $reportLines[] = str_repeat("=", 100);
        $reportLines[] = "";
        
        if (empty($uniqueMounts)) {
            $reportLines[] = "No valid mount points found.";
        } else {
            foreach ($uniqueMounts as $mount => $info) {
                $reportLines[] = $this->formatFileSystemInfo($info);
            }
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
        
        $statusColor = $this->getStatusColor($info['status']);
        $lines[] = "Usage: {$info['usage_percent']}% [{$statusColor}{$info['status']}\033[0m]";
        
        if ($info['inodes'] !== null) {
            $inodes = $info['inodes'];
            $inodePercent = $inodes['usage_percent'] . '%';
            $inodeStatus = $this->getUsageStatus($inodes['usage_percent']);
            $inodeColor = $this->getStatusColor($inodeStatus);
            $lines[] = "Inodes: {$inodes['used']}/{$inodes['total']} ({$inodeColor}{$inodePercent}\033[0m used)";
        }
        
        $barWidth = 40;
        $usedBars = (int)round(($info['usage_percent'] / 100) * $barWidth);
        $usedBars = min($usedBars, $barWidth);
        
        $colorCode = $this->getBarColor($info['status']);
        $lines[] = "[" . $colorCode . str_repeat("█", $usedBars) . "\033[0m" . str_repeat("░", $barWidth - $usedBars) . "]";
        $lines[] = "";
        
        return implode("\n", $lines);
    }
    
    private function getStatusColor(string $status): string {
        switch ($status) {
            case self::STATUS_CRITICAL:
                return "\033[31m";
            case self::STATUS_WARNING:
                return "\033[33m";
            case self::STATUS_OK:
                return "\033[32m";
            default:
                return "\033[37m";
        }
    }
    
    private function getBarColor(string $status): string {
        switch ($status) {
            case self::STATUS_CRITICAL:
                return "\033[41;37m";
            case self::STATUS_WARNING:
                return "\033[43;37m";
            case self::STATUS_OK:
                return "\033[42;37m";
            default:
                return "\033[47;30m";
        }
    }
    
    private function generateSummaryTable(array $uniqueMounts): string {
        $lines = [];
        
        $lines[] = "SUMMARY TABLE (Unique Mount Points)";
        $lines[] = str_repeat("-", 110);
        $lines[] = sprintf("%-15s %-10s %-12s %-12s %-12s %-8s %-10s %s", 
            "Mount", "FS Type", "Size", "Used", "Available", "Use%", "Status", "Inodes");
        $lines[] = str_repeat("-", 110);
        
        if (empty($uniqueMounts)) {
            $lines[] = sprintf("%-85s", "No mount points available");
        } else {
            foreach ($uniqueMounts as $mount => $info) {
                if (isset($info['error'])) {
                    $lines[] = sprintf("%-15s %-70s", $info['path'], "ERROR: " . $info['error']);
                    continue;
                }
                
                $inodeInfo = "N/A";
                if ($info['inodes'] !== null) {
                    $inodeInfo = $info['inodes']['usage_percent'] . '%';
                }
                
                $colorCode = $this->getStatusColor($info['status']);
                
                $lines[] = sprintf("%-15s %-10s %-12s %-12s %-12s %-8s %s%-10s\033[0m %s",
                    $info['mount_point'],
                    substr($info['file_system'], 0, 10),
                    $this->formatBytes($info['total'], 1),
                    $this->formatBytes($info['used'], 1),
                    $this->formatBytes($info['free'], 1),
                    $info['usage_percent'] . '%',
                    $colorCode,
                    $info['status'],
                    $inodeInfo
                );
            }
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
        $unknownCount = 0;
        
        foreach ($uniqueMounts as $info) {
            if (!isset($info['error'])) {
                $totalSpace += $info['total'];
                $totalUsed += $info['used'];
                
                if ($info['status'] === self::STATUS_CRITICAL) $criticalCount++;
                elseif ($info['status'] === self::STATUS_WARNING) $warningCount++;
                elseif ($info['status'] === self::STATUS_UNKNOWN) $unknownCount++;
            }
        }
        
        return [
            'total_filesystems' => count($uniqueMounts),
            'total_space' => $totalSpace,
            'total_used' => $totalUsed,
            'total_free' => $totalSpace - $totalUsed,
            'total_usage_percent' => $totalSpace > 0 ? round(($totalUsed / $totalSpace) * 100, 2) : 0,
            'critical_count' => $criticalCount,
            'warning_count' => $warningCount,
            'ok_count' => count($uniqueMounts) - $criticalCount - $warningCount - $unknownCount,
            'unknown_count' => $unknownCount
        ];
    }
    
    public function toJson(): string {
        return json_encode([
            'timestamp' => date('c'),
            'config' => [
                'warning_threshold' => $this->config['warning_threshold'],
                'critical_threshold' => $this->config['critical_threshold'],
                'timeout' => $this->config['timeout']
            ],
            'filesystems' => array_values($this->getUniqueMountPoints()),
            'summary' => $this->getSystemSummary()
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
    
    public function toArray(): array {
        return [
            'timestamp' => date('c'),
            'config' => [
                'warning_threshold' => $this->config['warning_threshold'],
                'critical_threshold' => $this->config['critical_threshold'],
                'timeout' => $this->config['timeout']
            ],
            'filesystems' => array_values($this->getUniqueMountPoints()),
            'summary' => $this->getSystemSummary()
        ];
    }
    
    public function addScanPath(string $path): void {
        if (!in_array($path, $this->config['scan_paths'])) {
            $this->config['scan_paths'][] = $path;
        }
    }
    
    public function removeScanPath(string $path): void {
        $key = array_search($path, $this->config['scan_paths']);
        if ($key !== false) {
            unset($this->config['scan_paths'][$key]);
            $this->config['scan_paths'] = array_values($this->config['scan_paths']);
        }
    }
}

if (PHP_SAPI === 'cli') {
    try {
        $analyzer = new FileSystemAnalyzer([
            'warning_threshold' => 80,
            'critical_threshold' => 90,
            'timeout' => 30
        ]);
        
        if (function_exists('posix_isatty') && posix_isatty(STDOUT)) {
            echo $analyzer->generateTextReport();
        } else {
            $report = $analyzer->generateTextReport();
            $report = preg_replace('/\033\[[0-9;]*m/', '', $report);
            echo $report;
        }
        
        $summary = $analyzer->getSystemSummary();
        echo "\nSYSTEM SUMMARY:\n";
        echo "Unique Mount Points: {$summary['total_filesystems']}\n";
        echo "Total Space: " . $analyzer->formatBytes($summary['total_space']) . "\n";
        echo "Total Used: " . $analyzer->formatBytes($summary['total_used']) . "\n";
        echo "Total Free: " . $analyzer->formatBytes($summary['total_free']) . "\n";
        echo "Overall Usage: {$summary['total_usage_percent']}%\n";
        echo "Status - Critical: {$summary['critical_count']}, Warning: {$summary['warning_count']}, OK: {$summary['ok_count']}" . 
             ($summary['unknown_count'] > 0 ? ", Unknown: {$summary['unknown_count']}" : "") . "\n";
        
        $mountPoints = $analyzer->getUniqueMountPoints();
        if (!empty($mountPoints)) {
            echo "\nDetected Mount Points:\n";
            foreach ($mountPoints as $mount => $info) {
                echo "  - {$mount} (" . substr($info['file_system'], 0, 20) . ")\n";
            }
        }
        
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
}
