<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@statamic/cms/inertia';
import { dateFormatter } from '@statamic/cms/api';
import {
    Badge,
    Button,
    ButtonGroup,
    Card,
    CommandPaletteItem,
    ConfirmationModal,
    Description,
    DocsCallout,
    Dropdown,
    DropdownItem,
    DropdownMenu,
    Header,
    Heading,
    Input,
    Listing,
    Panel,
    PanelHeader,
} from '@statamic/cms/ui';

const props = defineProps({
    partner: { type: Object, required: true },
    stats: { type: Object, required: true },
    commissions: { type: Array, default: () => [] },
    commissionColumns: { type: Array, default: () => [] },
    payouts: { type: Array, default: () => [] },
    contracts: { type: Array, default: () => [] },
    locale: { type: String, default: null },
    indexUrl: { type: String, required: true },
    editUrl: { type: String, default: null },
    statusUrl: { type: String, default: null },
    inviteUrl: { type: String, default: null },
});

const statusColour = { pending: 'amber', active: 'green', rejected: 'red', suspended: 'gray' };
const commissionColour = { pending: 'amber', approved: 'blue', paid: 'green', reversed: 'red' };
const payoutColour = { open: 'amber', paid: 'green' };

const confirming = ref(null);
const busy = ref(false);

function setStatus(status) {
    busy.value = true;
    router.post(props.statusUrl, { status }, {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
            confirming.value = null;
        },
    });
}

function invite() {
    busy.value = true;
    router.post(props.inviteUrl, {}, {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
            confirming.value = null;
        },
    });
}

function confirm() {
    if (confirming.value === 'invite') invite();
    else setStatus(confirming.value);
}

// A row action (cancelling a commission) asks in core's modal, never a browser dialog.
const pendingAction = ref(null);

function run(action) {
    if (action.confirm) {
        pendingAction.value = action;
        return;
    }

    send(action);
}

function send(action) {
    busy.value = true;
    router.post(action.url, action.data ?? {}, {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
            pendingAction.value = null;
        },
    });
}

function formatDate(value) {
    if (!value) return null;

    return props.locale
        ? dateFormatter.withLocale(props.locale, () => dateFormatter.format(value, 'date'))
        : dateFormatter.format(value, 'date');
}

function copyLink() {
    navigator.clipboard?.writeText(props.partner.link);
    Statamic.$toast.success(__('affiliates::cp.link_copied'));
}
</script>

<template>
    <Head :title="[partner.name, __('affiliates::cp.partners')]" />

    <!-- Core's narrow variant for detail screens; data-max-width-wrapper keeps the header's full-width toggle working. -->
    <div class="max-w-5xl 3xl:max-w-6xl mx-auto" data-max-width-wrapper>
        <Header :title="partner.name" icon="users">
            <ButtonGroup role="group" :aria-label="__('affiliates::cp.partner_actions')">
                <Button :href="indexUrl" :text="__('affiliates::cp.back_to_partners')" variant="ghost" />

                <Dropdown v-if="statusUrl">
                    <DropdownMenu>
                        <DropdownItem v-if="inviteUrl" :text="__('affiliates::cp.send_invitation')" icon="mail" @click="confirming = 'invite'" />
                        <DropdownItem
                            v-if="partner.status === 'active'"
                            :text="__('affiliates::cp.suspend')"
                            icon="x"
                            variant="destructive"
                            @click="confirming = 'suspended'"
                        />
                        <DropdownItem
                            v-if="partner.status === 'pending'"
                            :text="__('affiliates::cp.reject')"
                            icon="x"
                            variant="destructive"
                            @click="confirming = 'rejected'"
                        />
                        <DropdownItem
                            v-if="partner.status === 'suspended' || partner.status === 'rejected'"
                            :text="__('affiliates::cp.reactivate')"
                            icon="checkmark"
                            @click="confirming = 'active'"
                        />
                    </DropdownMenu>
                </Dropdown>

                <Button v-if="editUrl" :href="editUrl" :text="__('affiliates::cp.edit')" />

                <CommandPaletteItem
                    v-if="statusUrl && partner.status === 'pending'"
                    category="Actions"
                    :text="__('affiliates::cp.approve')"
                    icon="checkmark"
                    :action="() => setStatus('active')"
                    prioritize
                    v-slot="{ text }"
                >
                    <Button variant="primary" :text="text" :loading="busy" @click="setStatus('active')" />
                </CommandPaletteItem>
            </ButtonGroup>
        </Header>

        <div class="grid lg:grid-cols-3 gap-6 mb-6">
            <Panel class="min-w-0 lg:col-span-2 h-full flex flex-col">
                <PanelHeader class="flex items-center justify-between min-h-10">
                    <Heading>{{ __('affiliates::cp.section_partner') }}</Heading>
                    <Badge pill :color="statusColour[partner.status] || 'gray'" :text="partner.status_label" />
                </PanelHeader>
                <Card class="flex-1">
                    <dl class="divide-y divide-content-border">
                        <div class="py-3 flex flex-wrap gap-2 justify-between">
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('affiliates::cp.col_email') }}</dt>
                            <dd>{{ partner.email }}</dd>
                        </div>
                        <div class="py-3 flex flex-wrap gap-2 justify-between">
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('affiliates::cp.col_code') }}</dt>
                            <dd class="font-mono text-sm">{{ partner.code }}</dd>
                        </div>
                        <div v-if="partner.coupon_codes.length" class="py-3 flex flex-wrap gap-2 justify-between">
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('affiliates::cp.field_coupon_codes') }}</dt>
                            <dd class="font-mono text-sm">{{ partner.coupon_codes.join(', ') }}</dd>
                        </div>
                        <div class="py-3 flex flex-wrap gap-2 justify-between">
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('affiliates::cp.field_commission_percent') }}</dt>
                            <dd>{{ partner.commission_percent || __('affiliates::cp.per_rate') }}</dd>
                        </div>
                        <div class="py-3 flex flex-wrap gap-2 justify-between">
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('affiliates::cp.field_payout_method') }}</dt>
                            <dd>
                                <template v-if="partner.payout_method">{{ partner.payout_method }}</template>
                                <span v-else class="text-gray-500 dark:text-gray-400">{{ __('affiliates::cp.method_missing') }}</span>
                            </dd>
                        </div>
                        <div class="py-3 flex flex-wrap gap-2 justify-between">
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('affiliates::cp.user_account') }}</dt>
                            <dd>{{ partner.user_linked ? __('affiliates::cp.user_linked') : __('affiliates::cp.user_not_linked') }}</dd>
                        </div>
                        <div v-if="partner.website" class="py-3 flex flex-wrap gap-2 justify-between">
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('affiliates::cp.website') }}</dt>
                            <dd class="truncate"><a :href="partner.website" target="_blank" rel="noopener" class="underline">{{ partner.website }}</a></dd>
                        </div>
                        <div v-if="partner.message" class="py-3">
                            <dt class="text-gray-500 dark:text-gray-400 mb-1">{{ __('affiliates::cp.application') }}</dt>
                            <dd class="whitespace-pre-line">{{ partner.message }}</dd>
                        </div>
                        <div v-if="partner.notes" class="py-3">
                            <dt class="text-gray-500 dark:text-gray-400 mb-1">{{ __('affiliates::cp.field_notes') }}</dt>
                            <dd class="whitespace-pre-line">{{ partner.notes }}</dd>
                        </div>
                    </dl>
                </Card>
            </Panel>

            <Panel class="min-w-0 h-full flex flex-col">
                <PanelHeader class="min-h-10 flex items-center">
                    <Heading>{{ __('affiliates::cp.figures') }}</Heading>
                </PanelHeader>
                <Card class="flex-1">
                    <dl class="divide-y divide-content-border">
                        <div class="py-3 flex justify-between gap-2">
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('affiliates::cp.clicks') }}</dt>
                            <dd class="font-medium">{{ stats.clicks }}</dd>
                        </div>
                        <div class="py-3 flex justify-between gap-2">
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('affiliates::cp.sales') }}</dt>
                            <dd class="font-medium">{{ stats.sales }}</dd>
                        </div>
                        <div class="py-3 flex justify-between gap-2">
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('affiliates::cp.conversion') }}</dt>
                            <dd class="font-medium">{{ stats.conversion ?? '—' }}</dd>
                        </div>
                        <template v-for="money in stats.money" :key="money.currency">
                            <div class="py-3 flex justify-between gap-2">
                                <dt class="text-gray-500 dark:text-gray-400">{{ __('affiliates::cp.status_pending') }}</dt>
                                <dd class="font-medium">{{ money.pending }}</dd>
                            </div>
                            <div class="py-3 flex justify-between gap-2">
                                <dt class="text-gray-500 dark:text-gray-400">{{ __('affiliates::cp.status_approved') }}</dt>
                                <dd class="font-medium">{{ money.approved }}</dd>
                            </div>
                            <div class="py-3 flex justify-between gap-2">
                                <dt class="text-gray-500 dark:text-gray-400">{{ __('affiliates::cp.status_paid') }}</dt>
                                <dd class="font-medium">{{ money.paid }}</dd>
                            </div>
                        </template>
                    </dl>
                </Card>
            </Panel>
        </div>

        <Panel v-if="partner.link" class="mb-6">
            <PanelHeader class="min-h-10 flex items-center">
                <Heading>{{ __('affiliates::cp.tracking_link') }}</Heading>
            </PanelHeader>
            <Card>
                <div class="flex gap-2 items-center">
                    <Input :model-value="partner.link" read-only class="font-mono" />
                    <Button icon="link" :text="__('affiliates::cp.copy')" @click="copyLink" />
                </div>
                <Description class="mt-2" :text="__('affiliates::cp.tracking_link_help')" />
            </Card>
        </Panel>

        <Panel class="mb-6">
            <PanelHeader class="min-h-10 flex items-center">
                <Heading>{{ __('affiliates::cp.commissions') }}</Heading>
            </PanelHeader>
            <Card v-if="commissions.length === 0">
                <Description :text="__('affiliates::cp.partner_no_commissions')" />
            </Card>
            <Listing
                v-else
                :items="commissions"
                :columns="commissionColumns"
                preferences-prefix="affiliates.partner-commissions"
                :allow-presets="false"
                @refreshing="router.reload()"
            >
                <template #cell-created_at="{ row }">
                    <span class="whitespace-nowrap">{{ formatDate(row.created_at) }}</span>
                </template>
                <template #cell-available_at="{ row }">
                    <span class="whitespace-nowrap">{{ formatDate(row.available_at) }}</span>
                </template>
                <template #cell-status="{ row }">
                    <Badge pill :color="commissionColour[row.status] || 'gray'" :text="row.status_label" />
                </template>
                <template #prepended-row-actions="{ row }">
                    <DropdownItem
                        v-for="action in row.row_actions"
                        :key="action.url"
                        :text="action.text"
                        :icon="action.icon"
                        :variant="action.destructive ? 'destructive' : 'default'"
                        @click="run(action)"
                    />
                </template>
            </Listing>
        </Panel>

        <div class="grid lg:grid-cols-2 gap-6">
            <Panel class="min-w-0">
                <PanelHeader class="min-h-10 flex items-center">
                    <Heading>{{ __('affiliates::cp.payouts') }}</Heading>
                </PanelHeader>
                <Card>
                    <Description v-if="payouts.length === 0" :text="__('affiliates::cp.partner_no_payouts')" />
                    <ul v-else class="divide-y divide-content-border">
                        <li v-for="payout in payouts" :key="payout.id" class="py-2 flex flex-wrap gap-2 items-center justify-between">
                            <span class="font-mono text-xs">{{ payout.reference }}</span>
                            <span class="text-gray-500 dark:text-gray-400 text-sm">{{ formatDate(payout.created_at) }}</span>
                            <span class="font-medium">{{ payout.amount }}</span>
                            <Badge pill :color="payoutColour[payout.status] || 'gray'" :text="payout.status_label" />
                        </li>
                    </ul>
                </Card>
            </Panel>

            <Panel class="min-w-0">
                <PanelHeader class="min-h-10 flex items-center">
                    <Heading>{{ __('affiliates::cp.jv') }}</Heading>
                </PanelHeader>
                <Card>
                    <Description v-if="contracts.length === 0" :text="__('affiliates::cp.partner_no_contracts')" />
                    <ul v-else class="divide-y divide-content-border">
                        <li v-for="contract in contracts" :key="contract.url" class="py-2 flex gap-2 items-center justify-between">
                            <Link :href="contract.url" class="underline">{{ contract.name }}</Link>
                            <span>{{ contract.percent }}</span>
                            <Badge pill :color="contract.active ? 'green' : 'gray'" :text="contract.active ? __('affiliates::cp.active') : __('affiliates::cp.inactive')" />
                        </li>
                    </ul>
                </Card>
            </Panel>
        </div>

        <ConfirmationModal
            :open="confirming !== null"
            :title="confirming === 'invite' ? __('affiliates::cp.send_invitation') : __('affiliates::cp.change_status')"
            :button-text="confirming === 'invite' ? __('affiliates::cp.send_invitation') : __('affiliates::cp.confirm')"
            :cancel-text="__('affiliates::cp.cancel')"
            :busy="busy"
            :danger="confirming === 'suspended' || confirming === 'rejected'"
            @update:open="(open) => { if (!open && !busy) confirming = null; }"
            @cancel="confirming = null"
            @confirm="confirm"
        >
            <Description
                :text="confirming === 'invite'
                    ? __('affiliates::cp.invite_confirm', { email: partner.email })
                    : __('affiliates::cp.status_confirm_' + confirming)"
            />
        </ConfirmationModal>

        <ConfirmationModal
            :open="pendingAction !== null"
            :title="pendingAction?.text"
            :button-text="pendingAction?.text"
            :cancel-text="__('affiliates::cp.cancel')"
            :busy="busy"
            danger
            @update:open="(open) => { if (!open && !busy) pendingAction = null; }"
            @cancel="pendingAction = null"
            @confirm="send(pendingAction)"
        >
            <Description :text="pendingAction?.confirm" />
        </ConfirmationModal>

        <DocsCallout :topic="__('affiliates::cp.nav')" url="https://docs.adriangoldner.dev/affiliates/" />
    </div>
</template>
