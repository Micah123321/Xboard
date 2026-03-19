<?php

namespace App\Models;

use App\Protocols\Clash;
use App\Protocols\ClashMeta;
use App\Protocols\SingBox;
use App\Protocols\Stash;
use App\Protocols\Surfboard;
use App\Protocols\Surge;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class SubscribeTemplate extends Model
{
    protected $table = 'v2_subscribe_templates';
    protected $guarded = [];
    protected $casts = [
        'name' => 'string',
        'content' => 'string',
    ];

    private static string $cachePrefix = 'subscribe_template:';
    private static array $legacyTemplateFiles = [
        'singbox' => [SingBox::CUSTOM_TEMPLATE_FILE, SingBox::DEFAULT_TEMPLATE_FILE],
        'clash' => [Clash::CUSTOM_TEMPLATE_FILE, Clash::DEFAULT_TEMPLATE_FILE],
        'clashmeta' => [
            ClashMeta::CUSTOM_TEMPLATE_FILE,
            ClashMeta::CUSTOM_CLASH_TEMPLATE_FILE,
            ClashMeta::DEFAULT_TEMPLATE_FILE,
        ],
        'stash' => [
            Stash::CUSTOM_TEMPLATE_FILE,
            Stash::CUSTOM_CLASH_TEMPLATE_FILE,
            Stash::DEFAULT_TEMPLATE_FILE,
        ],
        'surge' => [Surge::CUSTOM_TEMPLATE_FILE, Surge::DEFAULT_TEMPLATE_FILE],
        'surfboard' => [Surfboard::CUSTOM_TEMPLATE_FILE, Surfboard::DEFAULT_TEMPLATE_FILE],
    ];

    public static function getContent(string $name): ?string
    {
        $cacheKey = self::$cachePrefix . $name;

        return Cache::store('redis')->remember($cacheKey, 3600, function () use ($name) {
            return self::resolveContent($name);
        });
    }

    public static function setContent(string $name, ?string $content): void
    {
        try {
            self::updateOrCreate(
                ['name' => $name],
                ['content' => $content]
            );
        } catch (QueryException) {
            admin_setting([self::legacySettingKey($name) => $content]);
        }
        Cache::store('redis')->forget(self::$cachePrefix . $name);
    }

    public static function getAllContents(): array
    {
        try {
            $contents = self::pluck('content', 'name')->toArray();
            return $contents ?: self::getLegacyContents();
        } catch (QueryException) {
            return self::getLegacyContents();
        }
    }

    public static function flushCache(string $name): void
    {
        Cache::store('redis')->forget(self::$cachePrefix . $name);
    }

    /**
     * Resolve template content with backwards-compatible fallbacks.
     *
     * @param string $name
     * @return string|null
     */
    private static function resolveContent(string $name): ?string
    {
        try {
            $template = self::query()
                ->select('content')
                ->where('name', $name)
                ->first();

            if ($template !== null) {
                return $template->content;
            }
        } catch (QueryException) {
            // Fall back to legacy sources when the new table is unavailable.
        }

        return self::getLegacyContent($name);
    }

    /**
     * Collect all legacy template contents by protocol name.
     *
     * @return array<string, string>
     */
    private static function getLegacyContents(): array
    {
        $contents = [];

        foreach (array_keys(self::$legacyTemplateFiles) as $name) {
            $contents[$name] = self::getLegacyContent($name) ?? '';
        }

        return $contents;
    }

    /**
     * Resolve template content from legacy settings and bundled files.
     *
     * @param string $name
     * @return string|null
     */
    private static function getLegacyContent(string $name): ?string
    {
        $settingValue = admin_setting(self::legacySettingKey($name));
        if ($settingValue !== null && $settingValue !== '') {
            return $settingValue;
        }

        foreach (self::$legacyTemplateFiles[$name] ?? [] as $file) {
            $path = base_path($file);
            if (File::exists($path)) {
                return File::get($path);
            }
        }

        return null;
    }

    /**
     * Build the legacy settings key for a template name.
     *
     * @param string $name
     * @return string
     */
    private static function legacySettingKey(string $name): string
    {
        return "subscribe_template_{$name}";
    }
}
