<?php

namespace Tests\Unit\Admin;

use App\Http\Requests\Admin\UserUpdate;
use App\Services\Plugin\HookManager;
use Tests\TestCase;

class UserPluginHookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        HookManager::reset();
    }

    protected function tearDown(): void
    {
        HookManager::reset();
        parent::tearDown();
    }

    public function test_user_update_rules_and_messages_are_filterable(): void
    {
        HookManager::registerFilter('admin.user.update.rules', function (array $rules): array {
            $rules['plugin_note'] = 'nullable|string';
            return $rules;
        });
        HookManager::registerFilter('admin.user.update.messages', function (array $messages): array {
            $messages['plugin_note.string'] = 'plugin note must be text';
            return $messages;
        });

        $request = new UserUpdate();

        $this->assertSame('nullable|string', $request->rules()['plugin_note']);
        $this->assertSame('plugin note must be text', $request->messages()['plugin_note.string']);
    }
}
