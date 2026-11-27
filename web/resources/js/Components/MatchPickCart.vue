<script setup>
import { formatPoints, pickStatusText } from '@/lib/rating';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    /** Событие в корзине: { outcomeId, market, label, prob, points } — максимум одно на матч */
    cart: { type: Object, default: null },
    /** Сохранённый выбор: { market, label, probability, potential_points, status, points } */
    userPick: { type: Object, default: null },
    pickOpen: { type: Boolean, default: false },
    isGuest: { type: Boolean, default: true },
    busy: { type: Boolean, default: false },
    error: { type: String, default: '' },
    justSaved: { type: Boolean, default: false },
});

const emit = defineEmits(['place', 'clear', 'cancel']);

const replacing = computed(
    () => props.cart && props.userPick && props.pickOpen && props.userPick.outcome_id !== props.cart.outcomeId,
);
const visible = computed(() => !!props.cart || !!props.userPick);
const settled = computed(() => props.userPick && ['won', 'lost', 'void'].includes(props.userPick.status));
const loginHref = computed(() => route('login'));
const registerHref = computed(() => route('register'));
</script>

<template>
    <Transition name="miq-cart">
        <aside
            v-if="visible"
            class="miq-cart-dock"
            aria-label="Корзина события"
            aria-live="polite"
        >
            <div class="miq-cart" :class="{ 'is-saved': justSaved && !cart }">
                <!-- Событие в корзине -->
                <template v-if="cart">
                    <div class="miq-cart-head">
                        <span class="miq-cart-badge">Корзина <b>1</b></span>
                        <span class="miq-cart-rule">1 событие на матч</span>
                        <button
                            type="button"
                            class="miq-cart-x"
                            aria-label="Убрать событие из корзины"
                            :disabled="busy"
                            @click="emit('clear')"
                        >
                            ×
                        </button>
                    </div>

                    <div class="miq-cart-item">
                        <div class="min-w-0">
                            <p class="miq-cart-market">{{ cart.market }}</p>
                            <p class="miq-cart-label">{{ cart.label }}</p>
                        </div>
                        <div class="miq-cart-reward">
                            <span class="miq-cart-pts num-display">{{ formatPoints(cart.points) }}</span>
                            <span class="miq-cart-prob">вероятность {{ cart.prob }}%</span>
                        </div>
                    </div>

                    <p v-if="replacing" class="miq-cart-note">
                        Заменит ваш выбор: <b>{{ userPick.label }}</b>
                    </p>
                    <p v-if="error" class="miq-cart-error" role="alert">{{ error }}</p>

                    <template v-if="isGuest">
                        <Link :href="loginHref" class="miq-cart-cta">Войти и поставить</Link>
                        <p class="miq-cart-guest">
                            Нет аккаунта?
                            <Link :href="registerHref" class="font-semibold underline-offset-2 hover:underline">Регистрация</Link>
                            · выбор в корзине сохранится
                        </p>
                    </template>
                    <button
                        v-else
                        type="button"
                        class="miq-cart-cta"
                        :disabled="busy"
                        @click="emit('place')"
                    >
                        {{ busy ? 'Сохраняем…' : replacing ? 'Заменить выбор' : 'Поставить' }}
                    </button>
                </template>

                <!-- Уже сохранённый выбор -->
                <template v-else-if="userPick">
                    <div class="miq-cart-head">
                        <span class="miq-cart-badge" :class="{ 'is-won': userPick.status === 'won', 'is-lost': userPick.status === 'lost' }">
                            {{ justSaved ? 'Выбор сохранён' : 'Ваш выбор на матч' }}
                        </span>
                    </div>

                    <div class="miq-cart-item">
                        <div class="min-w-0">
                            <p class="miq-cart-market">{{ userPick.market }}</p>
                            <p class="miq-cart-label">{{ userPick.label }}</p>
                        </div>
                        <div class="miq-cart-reward">
                            <span
                                class="miq-cart-pts num-display"
                                :class="{ 'is-won': userPick.status === 'won', 'is-lost': userPick.status === 'lost' }"
                            >
                                <template v-if="userPick.status === 'won'">{{ formatPoints(userPick.points) }}</template>
                                <template v-else-if="userPick.status === 'lost'">0 баллов</template>
                                <template v-else>{{ formatPoints(userPick.potential_points) }}</template>
                            </span>
                            <span class="miq-cart-prob">
                                {{ settled ? pickStatusText(userPick) : `вероятность ${userPick.probability}% · при успехе` }}
                            </span>
                        </div>
                    </div>

                    <p v-if="error" class="miq-cart-error" role="alert">{{ error }}</p>
                    <p v-if="pickOpen" class="miq-cart-hint">
                        Хотите другое? Выберите событие — оно заменит текущее.
                        <button type="button" class="miq-cart-link" :disabled="busy" @click="emit('cancel')">
                            Снять выбор
                        </button>
                    </p>
                </template>
            </div>
        </aside>
    </Transition>
</template>
