<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  Aim,
  CircleCheck,
  Clock,
  Picture,
  Present,
  Setting,
} from '@element-plus/icons-vue'
import {
  createGiftCardTemplate,
  updateGiftCardTemplate,
} from '@/api/admin'
import type {
  AdminGiftCardTemplateItem,
  AdminPlanOption,
} from '@/types/api'
import {
  buildGiftCardTypeOptions,
  createGiftCardTemplateFormModel,
  toGiftCardTemplateFormModel,
  toGiftCardTemplatePayload,
  type GiftCardOption,
  type GiftCardTemplateFormModel,
} from '@/utils/giftCards'

type DrawerMode = 'create' | 'edit'

const props = defineProps<{
  visible: boolean
  mode: DrawerMode
  template?: AdminGiftCardTemplateItem | null
  plans: AdminPlanOption[]
  typeMap?: Record<string, string> | null
}>()

const emit = defineEmits<{
  (e: 'update:visible', value: boolean): void
  (e: 'success'): void
}>()

const formRef = ref<FormInstance>()
const saving = ref(false)
const form = reactive<GiftCardTemplateFormModel>(createGiftCardTemplateFormModel())

const title = computed(() => props.mode === 'edit' ? '编辑模板' : '添加模板')
const typeOptions = computed<GiftCardOption<1 | 2 | 3>[]>(() => buildGiftCardTypeOptions(props.typeMap))

const rules: FormRules<GiftCardTemplateFormModel> = {
  name: [{ required: true, message: '请输入模板名称', trigger: 'blur' }],
  type: [{ required: true, message: '请选择模板类型', trigger: 'change' }],
}

function patchForm(nextForm: GiftCardTemplateFormModel) {
  Object.assign(form, createGiftCardTemplateFormModel(), nextForm)
}

function closeDrawer() {
  emit('update:visible', false)
}

async function handleSubmit() {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) {
    return
  }

  if (form.type === 2 && !form.plan_id) {
    ElMessage.warning('套餐礼品卡需要先选择目标套餐')
    return
  }

  saving.value = true
  try {
    const payload = toGiftCardTemplatePayload(form)
    if (props.mode === 'edit' && form.id) {
      await updateGiftCardTemplate({ ...payload, id: form.id })
      ElMessage.success('礼品卡模板已更新')
    } else {
      await createGiftCardTemplate(payload)
      ElMessage.success('礼品卡模板已创建')
    }
    emit('success')
    closeDrawer()
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '礼品卡模板保存失败')
  } finally {
    saving.value = false
  }
}

watch(
  () => props.visible,
  (visible) => {
    if (!visible) {
      return
    }
    patchForm(toGiftCardTemplateFormModel(props.template))
  },
  { immediate: true },
)
</script>

<template>
  <ElDrawer
    :model-value="visible"
    :title="title"
    size="620px"
    direction="rtl"
    class="gift-card-template-drawer"
    @update:model-value="emit('update:visible', $event)"
  >
    <ElForm
      ref="formRef"
      :model="form"
      :rules="rules"
      label-position="top"
      class="template-form"
    >
      <section class="section-card">
        <header>
          <ElIcon><Setting /></ElIcon>
          <div>
            <strong>基础配置</strong>
            <span>管理模板名称、类型与基础开关。</span>
          </div>
        </header>

        <div class="form-grid two-columns">
          <ElFormItem label="模板名称" prop="name">
            <ElInput v-model="form.name" placeholder="请输入模板名称" />
          </ElFormItem>

          <ElFormItem label="类型" prop="type">
            <ElSelect v-model="form.type" placeholder="请选择模板类型">
              <ElOption
                v-for="item in typeOptions"
                :key="item.value"
                :label="item.label"
                :value="item.value"
              />
            </ElSelect>
          </ElFormItem>

          <ElFormItem label="描述" class="full-row">
            <ElInput
              v-model="form.description"
              type="textarea"
              :rows="4"
              placeholder="请输入礼品卡描述"
            />
          </ElFormItem>

          <ElFormItem label="排序">
            <ElInputNumber v-model="form.sort" :min="0" class="number-input" />
          </ElFormItem>

          <ElFormItem label="状态">
            <div class="switch-box">
              <div>
                <strong>{{ form.status ? '启用中' : '已停用' }}</strong>
                <span>禁用后，此模板将无法生成或兑换新的礼品卡。</span>
              </div>
              <ElSwitch v-model="form.status" />
            </div>
          </ElFormItem>
        </div>
      </section>

      <section class="section-card">
        <header>
          <ElIcon><Present /></ElIcon>
          <div>
            <strong>奖励内容</strong>
            <span>配置余额、流量、设备数与套餐奖励。</span>
          </div>
        </header>

        <div class="form-grid two-columns">
          <ElFormItem label="奖励余额（元）">
            <ElInputNumber v-model="form.balance_yuan" :min="0" :precision="2" class="number-input" />
          </ElFormItem>

          <ElFormItem label="奖励流量（GB）">
            <ElInputNumber v-model="form.transfer_gb" :min="0" :precision="2" class="number-input" />
          </ElFormItem>

          <ElFormItem label="延长有效期（天）">
            <ElInputNumber v-model="form.expire_days" :min="0" class="number-input" />
          </ElFormItem>

          <ElFormItem label="增加设备数">
            <ElInputNumber v-model="form.device_limit" :min="0" class="number-input" />
          </ElFormItem>

          <ElFormItem label="邀请人奖励比例" class="full-row">
            <ElInputNumber
              v-model="form.invite_reward_rate"
              :min="0"
              :max="1"
              :step="0.05"
              :precision="2"
              class="number-input"
            />
          </ElFormItem>

          <ElFormItem label="重置当月流量" class="full-row">
            <div class="switch-box switch-box--soft">
              <div>
                <strong>开启后，将给符合条件的用户直接重置当月流量。</strong>
                <span>适合流量补偿、活动回馈和节假日赠送场景。</span>
              </div>
              <ElSwitch v-model="form.reset_package" />
            </div>
          </ElFormItem>

          <template v-if="form.type === 2">
            <ElFormItem label="目标套餐">
              <ElSelect v-model="form.plan_id" placeholder="请选择礼包对应套餐">
                <ElOption
                  v-for="plan in plans"
                  :key="plan.id"
                  :label="plan.name"
                  :value="plan.id"
                />
              </ElSelect>
            </ElFormItem>

            <ElFormItem label="套餐有效期（天）">
              <ElInputNumber v-model="form.plan_validity_days" :min="0" class="number-input" />
            </ElFormItem>
          </template>
        </div>
      </section>

      <section class="section-card">
        <header>
          <ElIcon><Aim /></ElIcon>
          <div>
            <strong>使用条件</strong>
            <span>限制哪些用户可以兑换该模板。</span>
          </div>
        </header>

        <div class="form-grid two-columns">
          <ElFormItem label="新用户注册天数限制">
            <ElInputNumber v-model="form.new_user_max_days" :min="0" class="number-input" />
          </ElFormItem>

          <ElFormItem label="允许的套餐">
            <ElSelect
              v-model="form.allowed_plan_ids"
              multiple
              filterable
              collapse-tags
              collapse-tags-tooltip
              placeholder="选择允许兑换的套餐（留空则不限）"
            >
              <ElOption
                v-for="plan in plans"
                :key="plan.id"
                :label="plan.name"
                :value="plan.id"
              />
            </ElSelect>
          </ElFormItem>

          <ElFormItem label="仅限新用户">
            <ElSwitch v-model="form.new_user_only" />
          </ElFormItem>

          <ElFormItem label="仅限付费用户">
            <ElSwitch v-model="form.paid_user_only" />
          </ElFormItem>

          <ElFormItem label="需要邀请关系">
            <ElSwitch v-model="form.require_invite" />
          </ElFormItem>
        </div>
      </section>

      <section class="section-card">
        <header>
          <ElIcon><CircleCheck /></ElIcon>
          <div>
            <strong>使用限制</strong>
            <span>限制单用户可使用次数和冷却时间。</span>
          </div>
        </header>

        <div class="form-grid two-columns">
          <ElFormItem label="单用户最大使用次数">
            <ElInputNumber v-model="form.max_use_per_user" :min="0" class="number-input" />
          </ElFormItem>

          <ElFormItem label="同类卡冷却时间（小时）">
            <ElInputNumber v-model="form.cooldown_hours" :min="0" class="number-input" />
          </ElFormItem>
        </div>
      </section>

      <section class="section-card">
        <header>
          <ElIcon><Clock /></ElIcon>
          <div>
            <strong>特殊配置</strong>
            <span>用于配置节日活动与倍率加成。</span>
          </div>
        </header>

        <div class="form-grid two-columns">
          <ElFormItem label="活动开始时间">
            <ElDatePicker
              v-model="form.festival_start_at"
              type="datetime"
              value-format="X"
              class="date-input"
              placeholder="请选择开始时间"
            />
          </ElFormItem>

          <ElFormItem label="活动结束时间">
            <ElDatePicker
              v-model="form.festival_end_at"
              type="datetime"
              value-format="X"
              class="date-input"
              placeholder="请选择结束时间"
            />
          </ElFormItem>

          <ElFormItem label="节日奖励系数" class="full-row">
            <ElInputNumber v-model="form.festival_bonus" :min="0" :precision="2" :step="0.1" class="number-input" />
          </ElFormItem>
        </div>
      </section>

      <section class="section-card">
        <header>
          <ElIcon><Picture /></ElIcon>
          <div>
            <strong>显示效果</strong>
            <span>管理卡片 icon、背景图与主题色。</span>
          </div>
        </header>

        <div class="form-grid two-columns">
          <ElFormItem label="图标 URL">
            <ElInput v-model="form.icon" placeholder="请输入图标的 URL" />
          </ElFormItem>

          <ElFormItem label="背景图片 URL">
            <ElInput v-model="form.background_image" placeholder="请输入背景图片的 URL" />
          </ElFormItem>

          <ElFormItem label="主题色" class="full-row">
            <div class="color-row">
              <ElColorPicker v-model="form.theme_color" />
              <ElInput v-model="form.theme_color" placeholder="#0071e3" />
            </div>
          </ElFormItem>
        </div>
      </section>
    </ElForm>

    <template #footer>
      <div class="drawer-footer">
        <ElButton @click="closeDrawer">取消</ElButton>
        <ElButton type="primary" :loading="saving" @click="handleSubmit">
          {{ mode === 'edit' ? '保存修改' : '创建模板' }}
        </ElButton>
      </div>
    </template>
  </ElDrawer>
</template>

<style lang="scss" src="./GiftCardTemplateDrawer.scss"></style>
