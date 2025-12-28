<?php
use usualtool\Sync\Sync;
$local = APP_ROOT . '/assets';
$remote = '/www/wwwroot/ut6/app/assets';
$servers = [
    [
        'host' => '172.168.0.1',
        'user' => 'root',
        'password' => '123456'
        // 如果要用私钥：'privateKey' => file_get_contents('/home/main/.ssh/sync_key')
    ]
    //可以多组从服务器同步
];
foreach($servers as $config) {
    echo "正在同步到 {$config['host']}...<br/>";
    try {
        $sync = new Sync(
            $config['host'],
            $config['user'],
            22,
            $config['password'] ?? null,
            $config['privateKey'] ?? null
        );
        $sync->SetMaxRetries(3, 2);
        $sync->setLogger(function ($level, $message) {
            if ($level === 'INFO' && strpos($message, '上传: ') === 0) {
                $relPath = substr($message, strlen('上传: '));
                echo "{$relPath} 同步成功<br/>";
            }
        });
        $result = $sync->SyncTo($local, $remote, false);
        echo "同步完成: 共上传 {$result['uploaded']} 个文件<br/>";
        if (!empty($result['errors'])) {
            echo "以下操作出错:<br/>";
            foreach ($result['errors'] as $err) {
                echo "  - $err<br/>";
            }
        }
    } catch (\Exception $e) {
        echo "同步失败 ({$config['host']}): " . $e->getMessage() . "<br/>";
    }
}
