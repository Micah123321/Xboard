<?php

return [
    'snapshot_path' => __DIR__ . '/../resources/data/access-traffic/geosite.json',
    'update_path' => __DIR__ . '/../storage/app/access-traffic/geosite.json',
    'auto_update' => true,
    // General platform lists identify services, not individual playback or download actions.
    'groups' => [
        'netflix' => ['Netflix', 'video'],
        'youtube' => ['YouTube', 'web'],
        'bilibili' => ['哔哩哔哩', 'web'],
        'tiktok' => ['TikTok', 'web'],
        'iqiyi' => ['爱奇艺', 'video'],
        'youku' => ['优酷', 'video'],
        'tencent' => ['腾讯平台', 'web'],
        'douyin' => ['抖音平台', 'web'],
        'kuaishou' => ['快手平台', 'web'],
        'twitch' => ['Twitch', 'video'],
        'spotify' => ['Spotify', 'web'],
        'steam' => ['Steam', 'web'],
        'apple' => ['Apple', 'web'],
        'microsoft' => ['Microsoft', 'web'],
        'google' => ['Google', 'web'],
        'github' => ['GitHub', 'web'],
        'telegram' => ['Telegram', 'web'],
        'dropbox' => ['Dropbox', 'sync'],
        'onedrive' => ['OneDrive', 'sync'],
        'baidu' => ['百度平台', 'web'],
        'chinamobile' => ['中国移动 / 咪咕平台', 'web'],
        'aliyun' => ['阿里云', 'cdn'],
        'cloudflare' => ['Cloudflare', 'cdn'],
        'amazon' => ['Amazon', 'web'],
    ],
];
