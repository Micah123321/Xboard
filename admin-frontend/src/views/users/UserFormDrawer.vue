<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { createUser, fetchUsers, updateUser } from '@/api/admin'
import type { AdminPlanOption, AdminUserListItem } from '@/types/api'
import {
  COMMISSION_TYPE_OPTIONS,
  buildUserFilters,
  createEmptyUserForm,
  splitEmailAddress,
  toUserFormModel,
  toUserUpdatePayload,
  type UserFormModel,
} from '@/utils/users'

const props = defineProps<{
  visible: boolean
  mode: 'create' | 'edit'
  user?: AdminUserListItem | null
  plans: AdminPlanOption[]
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  success: [message: string]
}>()

const formRef = ref<FormInstance>()
const submitting = ref(false)
const form = reactive<UserFormModel>(createEmptyUserForm())

const drawerTitle = computed(() => props.mode === 'create' ? '创建用户' : '编辑用户')

const rules = computed<FormRules<UserFormModel>>(() => ({
  email: [
    { required: true, message: '请输入邮箱', trigger: 'blur' },
    { type: 'email', message: '请输入有效邮箱', trigger: ['blur', 'change'] },
  ],
  password: props.mode === 'create'
    ? [
        { required: true, message: '请输入密码', trigger: 'blur' },
        { min: 8, message: '密码至少 8 位', trigger: 'blur' },
      ]
    : [{ min: 8, message: '密码至少 8 位', trigger: 'blur' }],
}))

function closeDrawer() {
  emit('update:visible', false)
}

function syncForm() {
  Object.assign(form, toUserFormModel(props.user))
}

async function handleSubmit() {
  const instance = formRef.value
    if (!instance) {
      return
    }

  const valid = await instance.validate().catch(() => false)
  if (!valid) {
    return
  }

  submitting.value = true

  try {
    if (props.mode === 'create') {
      if (!splitEmailAddress(form.email)) {
        throw new Error('请输入有效的邮箱地址')
      }

      await createUser({
        email: form.email,
        password: form.password,
        plan_id: form.planId,
        expired_at: form.expiredAt,
      })

      const created = await fetchUsers({
        current: 1,
        pageSize: 1,
        filter: buildUserFilters(form.email, 'all', 'all'),
      })

      const createdUser = created.data.find((item) => item.email === form.email)
      if (!createdUser) {
        throw new Error('用户已创建，但未能回查到新记录')
      }

      await updateUser(toUserUpdatePayload({
        ...form,
        id: createdUser.id,
        password: '',
      }))

      ElMessage.success('用户已创建')
      emit('success', '用户已创建')
      closeDrawer()
      return
    }

    await updateUser(toUserUpdatePayload(form))
    ElMessage.success('用户资料已更新')
    emit('success', '用户资料已更新')
    closeDrawer()
  } finally {
    submitting.value = false
  }
}

watch(
  () => [props.visible, props.user, props.mode],
  ([visible]) => {
    if (!visible) {
      return
    }

    syncForm()
    formRef.value?.clearValidate()
  },
  { immediate: true },
)
</script>

<template>
  <ElDrawer
    :model-value="props.visible"
    :title="drawerTitle"
    size="min(520px, 100vw)"
    class="user-form-drawer"
    destroy-on-close
    @close="closeDrawer"
    @update:model-value="emit('update:visible', $event)"
  >
    <div class="drawer-shell">
      <div class="drawer-copy">
        <p>用户管理</p>
        <h2>{{ drawerTitle }}</h2>
        <span>表单字段与后端现有用户接口保持一致。</span>
      </div>

      <ElForm
        ref="formRef"
        :model="form"
        :rules="rules"
        label-position="top"
        class="drawer-form"
      >
        <div class="drawer-grid drawer-grid--single">
          <ElFormItem label="邮箱" prop="email">
            <ElInput v-model="form.email" placeholder="请输入邮箱" />
          </ElFormItem>
          <ElFormItem label="密码" prop="password">
            <ElInput
              v-model="form.password"
              type="password"
              show-password
              :placeholder="props.mode === 'create' ? '创建时必填' : '留空则不修改'"
            />
          </ElFormItem>
        </div>

        <div class="drawer-grid">
          <ElFormItem label="余额">
            <ElInputNumber v-model="form.balance" :min="0" :precision="2" :controls="false" />
          </ElFormItem>
          <ElFormItem label="佣金余额">
            <ElInputNumber v-model="form.commissionBalance" :min="0" :precision="2" :controls="false" />
          </ElFormItem>
          <ElFormItem label="上传">
            <ElInputNumber v-model="form.uploadGb" :min="0" :precision="2" :controls="false" />
          </ElFormItem>
          <ElFormItem label="下载">
            <ElInputNumber v-model="form.downloadGb" :min="0" :precision="2" :controls="false" />
          </ElFormItem>
          <ElFormItem label="总流量">
            <ElInputNumber v-model="form.totalTrafficGb" :min="0" :precision="2" :controls="false" />
          </ElFormItem>
          <ElFormItem label="到期时间">
            <ElDatePicker
              v-model="form.expiredAt"
              type="datetime"
              value-format="X"
              placeholder="长期有效"
              style="width: 100%"
            />
          </ElFormItem>
          <ElFormItem label="订阅计划">
            <ElSelect v-model="form.planId" clearable placeholder="请选择订阅">
              <ElOption
                v-for="plan in props.plans"
                :key="plan.id"
                :label="plan.name"
                :value="plan.id"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem label="用户状态">
            <ElSelect v-model="form.banned" placeholder="请选择状态">
              <ElOption :value="false" label="正常" />
              <ElOption :value="true" label="封禁" />
            </ElSelect>
          </ElFormItem>
          <ElFormItem label="佣金类型">
            <ElSelect v-model="form.commissionType" placeholder="请选择类型">
              <ElOption
                v-for="option in COMMISSION_TYPE_OPTIONS"
                :key="option.value"
                :label="option.label"
                :value="option.value"
              />
            </ElSelect>
          </ElFormItem>
          <ElFormItem label="推荐返利比例">
            <ElInputNumber v-model="form.commissionRate" :min="0" :max="100" :controls="false" />
          </ElFormItem>
          <ElFormItem label="专属折扣比例">
            <ElInputNumber v-model="form.discount" :min="0" :max="100" :controls="false" />
          </ElFormItem>
          <ElFormItem label="限速">
            <ElInputNumber v-model="form.speedLimit" :min="0" :controls="false" />
          </ElFormItem>
          <ElFormItem label="设备限制">
            <ElInputNumber v-model="form.deviceLimit" :min="0" :controls="false" />
          </ElFormItem>
          <ElFormItem label="邀请人邮箱">
            <ElInput v-model="form.inviteUserEmail" placeholder="请输入邀请人邮箱" />
          </ElFormItem>
        </div>

        <div class="drawer-grid drawer-grid--toggles">
          <ElFormItem label="是否管理员">
            <ElSwitch v-model="form.isAdmin" />
          </ElFormItem>
          <ElFormItem label="是否员工">
            <ElSwitch v-model="form.isStaff" />
          </ElFormItem>
        </div>

        <ElFormItem label="备注">
          <ElInput
            v-model="form.remarks"
            type="textarea"
            :rows="4"
            placeholder="请输入备注信息"
          />
        </ElFormItem>
      </ElForm>
    </div>

    <template #footer>
      <div class="drawer-actions">
        <ElButton @click="closeDrawer">取消</ElButton>
        <ElButton type="primary" :loading="submitting" @click="handleSubmit">
          {{ props.mode === 'create' ? '提交创建' : '保存修改' }}
        </ElButton>
      </div>
    </template>
  </ElDrawer>
</template>

<style scoped>
.drawer-shell {
  display: grid;
  gap: 20px;
}

.drawer-copy {
  display: grid;
  gap: 4px;
}

.drawer-copy p {
  font-size: 12px;
  color: var(--xboard-text-muted);
  letter-spacing: 0.18em;
  text-transform: uppercase;
}

.drawer-copy h2 {
  font-size: 30px;
  line-height: 1.08;
  color: var(--xboard-text-strong);
}

.drawer-copy span {
  color: var(--xboard-text-secondary);
  line-height: 1.47;
}

.drawer-form {
  display: grid;
  gap: 12px;
}

.drawer-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px 16px;
}

.drawer-grid--single,
.drawer-grid--toggles {
  grid-template-columns: 1fr;
}

.drawer-actions {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  width: 100%;
}

@media (max-width: 767px) {
  .drawer-grid {
    grid-template-columns: 1fr;
  }
}
</style>
