<?php
use usualtool\Sync\Sync;
$local = '/data/images';
$remote = '/data/images';
$servers = [
    [
        'host' => '192.168.1.12',
        'user' => 'syncuser',
        'password' => 'your_strong_password_here'//参数改privateKey值改file_get_contents('/home/main/.ssh/sync_key')
    ]
];
foreach ($servers as $config) {
    echo "\n正在同步到 {$config['host']}...\n";
    try {
        $sync = new Sync(
            $config['host'],
            $config['user'],
            22,
            $config['password'] ?? null,
            $config['privateKey'] ?? null
        );
        $sync->SetMaxRetries(3, 2);
        $result = $sync->SyncTo($local, $remote, enableDelete: false);
        echo "同步完成: 上传 {$result['uploaded']} 个文件\n";
        if (!empty($result['errors'])) {
            echo "以下操作出错:\n";
            foreach ($result['errors'] as $err) {
                echo "  - $err\n";
            }
        }
    } catch (\Exception $e) {
        echo "❌ 同步失败 ({$config['host']}): " . $e->getMessage() . "\n";
    }
}