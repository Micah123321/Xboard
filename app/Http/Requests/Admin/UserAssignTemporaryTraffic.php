<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UserAssignTemporaryTraffic extends FormRequest
{
    public function rules(): array
    {
        return [
            'id' => 'required|integer|exists:v2_user,id',
            'traffic_gb' => 'required|numeric|min:0.01',
        ];
    }

    public function messages(): array
    {
        return [
            'id.required' => '用户ID不能为空',
            'id.exists' => '用户不存在',
            'traffic_gb.required' => '流量不能为空',
            'traffic_gb.numeric' => '流量格式不正确',
            'traffic_gb.min' => '流量必须大于0',
        ];
    }
}
