<?php
namespace usualtool\Sync;
use phpseclib3\Net\SFTP;
use phpseclib3\Crypt\PublicKeyLoader;
class Sync{
    private string $host;
    private int $port;
    private string $user;
    private ?string $password = null;
    private ?string $privateKey = null;
    private int $maxRetries = 3;
    private int $retryDelay = 1;
    private $logger = null;
    public function __construct(
        string $host,
        string $user,
        int $port = 22,
        ?string $password = null,
        ?string $privateKey = null){
        if(!$password && !$privateKey) {
            throw new \InvalidArgumentException("必须提供密码或私钥");
        }
        $this->host = $host;
        $this->port = $port;
        $this->user = $user;
        $this->password = $password;
        $this->privateKey = $privateKey;
    }
    public function SetMaxRetries(int $retries, int $delay = 1): self{
        $this->maxRetries = $retries;
        $this->retryDelay = $delay;
        return $this;
    }
    public function SetLogger(callable $logger): self{
        $this->logger = $logger;
        return $this;
    }
    private function Connect(): SFTP{
        $lastException = null;
        for($i = 0; $i <= $this->maxRetries; $i++){
            try {
                $sftp = new SFTP($this->host, $this->port);
                $sftp->setTimeout(30);
                $loginSuccess = false;
                if($this->privateKey !== null){
                    $key = PublicKeyLoader::load($this->privateKey, '');
                    $loginSuccess = $sftp->login($this->user, $key);
                }else{
                    $loginSuccess = $sftp->login($this->user, $this->password);
                }
                if($loginSuccess){
                    if($i > 0){
                        echo "重连成功 (尝试 {$i} 次)\n";
                    }
                    return $sftp;
                }else{
                    throw new \RuntimeException("SFTP 登录失败");
                }
            } catch (\Exception $e) {
                $lastException = $e;
                echo "连接失败 (尝试 " . ($i + 1) . "/{$this->maxRetries}): " . $e->getMessage() . "\n";
                if($i < $this->maxRetries){
                    sleep($this->retryDelay);
                }
            }
        }
        throw new \RuntimeException("SFTP 连接失败，已重试 {$this->maxRetries} 次", 0, $lastException);
    }
    /**
     * 确保远程目录递归创建
     */
    public function EnsureRemote(SFTP $sftp, string $remoteDir): void{
        $remoteDir = $this->NormalizePath($remoteDir);
        if ($sftp->is_dir($remoteDir)) {
            return;
        }
        $parts = explode('/', ltrim($remoteDir, '/'));
        $current = '';
        foreach ($parts as $part) {
            if ($part === '') continue;
            $current .= '/' . $part;
            if (!$sftp->is_dir($current)) {
                if (!$sftp->mkdir($current)) {
                    throw new \RuntimeException("无法创建远程目录: $current");
                }
            }
        }
    }
    /**
     * 获取远程文件MD5指纹
     */
    public function GetFileMd5(SFTP $sftp, string $remotePath, int $maxFileSizeForDownload = 10 * 1024 * 1024): ?string{
        $remotePath = $this->NormalizePath($remotePath);
        $output = $sftp->exec('md5sum ' . escapeshellarg($remotePath) . ' 2>/dev/null');
        if(preg_match('/^([a-f0-9]{32})/', trim($output), $matches)){
            return $matches[1];
        }
        $stat = $sftp->stat($remotePath);
        if($stat && $stat['size'] <= $maxFileSizeForDownload){
            $content = $sftp->get($remotePath);
            return md5($content);
        }
        return null;
    }
    /**
     * 安全上传文件
     */
    public function UploadFile(SFTP $sftp, string $localPath, string $remotePath): bool{
        if(!file_exists($localPath)){
            throw new \RuntimeException("本地文件不存在: $localPath");
        }
        $remoteDir = dirname($this->NormalizePath($remotePath));
        $this->EnsureRemote($sftp, $remoteDir);
        return $sftp->put($remotePath, $localPath, SFTP::SOURCE_LOCAL_FILE);
    }
    /**
     * 删除远程文件
     */
    public function DeleteFile(SFTP $sftp, string $remotePath): bool{
        return $sftp->delete($this->NormalizePath($remotePath));
    }
    /**
     * 获取远程文件列表（安全递归）
     */
    public function ListRemote(SFTP $sftp, string $remoteBaseDir): array{
        $remoteBaseDir = $this->NormalizePath($remoteBaseDir);
        $files = [];
        $this->collectRemoteFiles($sftp, $remoteBaseDir, $remoteBaseDir, $files);
        return $files;
    }
    private function collectRemoteFiles(SFTP $sftp, string $baseDir, string $currentDir, array &$files): void{
        $list = $sftp->nlist($currentDir);
        if ($list === false) {
            return;
        }
        foreach ($list as $item) {
            if ($item === '.' || $item === '..') continue;
            $fullPath = $currentDir . '/' . $item;
            if ($sftp->is_dir($fullPath)) {
                $this->collectRemoteFiles($sftp, $baseDir, $fullPath, $files);
            } elseif ($sftp->is_file($fullPath)) {
                $relPath = ltrim(substr($fullPath, strlen($baseDir)), '/');
                $files[$relPath] = $fullPath;
            }
        }
    }
    /**
     * 规范化路径，防止 ../ 攻击
     */
    private function NormalizePath(string $path): string{
        if(strpos($path, '..') !== false){
            throw new \InvalidArgumentException("路径包含非法字符 '..'");
        }
        return str_replace('\\', '/', $path);
    }
    /**
     * 执行完整同步（主入口）
     */
    public function SyncTo(string $localBaseDir, string $remoteBaseDir, bool $enableDelete = false): array{
        $sftp = $this->Connect();
        $result = ['uploaded' => 0, 'deleted' => 0, 'errors' => []];
        $localManifest = $this->BuildManifest($localBaseDir);
        $remoteFiles = $this->ListRemote($sftp, $remoteBaseDir);
        $remoteManifest = [];
        foreach($remoteFiles as $relPath => $fullPath){
            $md5 = $this->GetFileMd5($sftp, $fullPath);
            $remoteManifest[$relPath] = $md5 ?? 'unknown';
        }
        foreach($localManifest as $relPath => $localMd5){
            $remotePath = $remoteBaseDir . '/' . $relPath;
            if(!isset($remoteManifest[$relPath]) || $remoteManifest[$relPath] !== $localMd5){
                try {
                    if($this->UploadFile($sftp, $localBaseDir . '/' . $relPath, $remotePath)){
                        $result['uploaded']++;
                        $this->log('INFO', "上传: $relPath");
                    }else{
                        $msg = "上传失败: $relPath";
                        $result['errors'][] = $msg;
                        $this->log('ERROR', $msg);
                    }
                } catch (\Exception $e) {
                    $msg = "上传异常 ($relPath): " . $e->getMessage();
                    $result['errors'][] = $msg;
                    $this->log('ERROR', $msg);
                }
            }
        }

        if ($enableDelete) {
            foreach($remoteManifest as $relPath => $md5){
                if(!isset($localManifest[$relPath])){
                    $remotePath = $remoteBaseDir . '/' . $relPath;
                    if($this->DeleteFile($sftp, $remotePath)){
                        $result['deleted']++;
                        $this->log('INFO', "删除: $relPath");
                    }else{
                        $msg = "删除失败: $relPath";
                        $result['errors'][] = $msg;
                        $this->log('ERROR', $msg);
                    }
                }
            }
        }
        $sftp->disconnect();
        $this->log('INFO', "同步完成: 上传={$result['uploaded']}, 删除={$result['deleted']}");
        return $result;
    }
    private function BuildManifest(string $dir): array{
        $manifest = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach($iterator as $file){
            if ($file->isFile()) {
                $relPath = ltrim(str_replace($dir, '', $file->getPathname()), '/\\');
                $manifest[$relPath] = md5_file($file->getPathname());
            }
        }
        return $manifest;
    }
    /**
     * 内部日志方法
     */
    private function log(string $level, string $message): void{
        if($this->logger !== null){
            call_user_func($this->logger, $level, $message);
        }
    }
}
