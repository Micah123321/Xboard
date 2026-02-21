# 在线设备限制设计

## 概述

本文说明 Xboard 在线设备限制功能的设计与实现方案。

## 设计目标

1. 精准控制
   - 精确统计在线设备数量
   - 实时监控设备状态
   - 准确识别设备身份

2. 性能优化
   - 对系统性能影响最小化
   - 高效追踪设备在线情况
   - 优化资源使用

3. 用户体验
   - 保持连接过程平滑
   - 提供清晰错误提示
   - 在超限场景下优雅处理

## 实现细节

### 1. 设备识别

#### 设备 ID 生成
```php
public function generateDeviceId($user, $request) {
    return md5(
        $user->id . 
        $request->header('User-Agent') . 
        $request->ip()
    );
}
```

#### 设备信息存储
```php
[
    'device_id' => 'unique_device_hash',
    'user_id' => 123,
    'ip' => '192.168.1.1',
    'user_agent' => 'Mozilla/5.0...',
    'last_active' => '2024-03-21 10:00:00'
]
```

### 2. 连接管理

#### 连接校验
```php
public function checkDeviceLimit($user, $deviceId) {
    $onlineDevices = $this->getOnlineDevices($user->id);
    
    if (count($onlineDevices) >= $user->device_limit) {
        if (!in_array($deviceId, $onlineDevices)) {
            throw new DeviceLimitExceededException();
        }
    }
    
    return true;
}
```

#### 设备状态更新
```php
public function updateDeviceStatus($userId, $deviceId) {
    Redis::hset(
        "user:{$userId}:devices",
        $deviceId,
        json_encode([
            'last_active' => now(),
            'status' => 'online'
        ])
    );
}
```

### 3. 清理机制

#### 非活跃设备清理
```php
public function cleanupInactiveDevices() {
    $inactiveThreshold = now()->subMinutes(30);
    
    foreach ($this->getUsers() as $user) {
        $devices = $this->getOnlineDevices($user->id);
        
        foreach ($devices as $deviceId => $info) {
            if ($info['last_active'] < $inactiveThreshold) {
                $this->removeDevice($user->id, $deviceId);
            }
        }
    }
}
```

## 错误处理

### 错误类型
1. 设备数超限
   ```php
   class DeviceLimitExceededException extends Exception {
       protected $message = 'Device limit exceeded';
       protected $code = 4001;
   }
   ```

2. 无效设备
   ```php
   class InvalidDeviceException extends Exception {
       protected $message = 'Invalid device';
       protected $code = 4002;
   }
   ```

### 错误消息
```php
return [
    'device_limit_exceeded' => 'Maximum number of devices reached',
    'invalid_device' => 'Device not recognized',
    'device_expired' => 'Device session expired'
];
```

## 性能考虑

1. 缓存策略
   - 使用 Redis 追踪设备状态
   - 设置缓存过期机制
   - 优化缓存结构

2. 数据库操作
   - 减少数据库查询次数
   - 使用批量操作
   - 实施查询优化

3. 内存管理
   - 采用高效数据结构
   - 定期清理过期数据
   - 持续监控内存使用

## 安全措施

1. 设备校验
   - 校验设备信息合法性
   - 检测可疑行为模式
   - 实施限流策略

2. 数据保护
   - 加密敏感信息
   - 实施访问控制
   - 定期安全审计

## 后续优化方向

1. 功能增强
   - 设备管理界面
   - 设备活动历史
   - 自定义设备名称

2. 性能优化
   - 改进缓存策略
   - 优化清理机制
   - 降低内存占用

3. 安全增强
   - 更高级的设备指纹识别
   - 欺诈检测
   - 更完善的加密机制

## 总结

该设计在保证性能和用户体验的同时，为在线设备数管理提供了稳健且高效的方案。通过持续监控和迭代优化，可长期保持系统的有效性与安全性。
