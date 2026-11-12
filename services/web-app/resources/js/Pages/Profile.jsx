import { Head, router, useForm, usePage } from '@inertiajs/react';
import AppLayout from '../Layouts/AppLayout';

export default function Profile({ pendingSecret, pendingUri }) {
    const { auth } = usePage().props;
    const user = auth.user;

    const pwForm = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const otpForm = useForm({ otp_code: '' });

    return (
        <AppLayout title="Profile & Security">
            <Head title="Profile" />
            <div className="grid gap-6 lg:grid-cols-2">
                <section className="rounded-xl border border-slate-800 bg-slate-900 p-6">
                    <h2 className="text-base font-semibold text-white">Change Password</h2>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            pwForm.put('/profile/password', { onSuccess: () => pwForm.reset() });
                        }}
                        className="mt-4 space-y-3"
                    >
                        <div>
                            <label className="mb-1 block text-xs text-slate-400">Current Password</label>
                            <input
                                type="password"
                                required
                                value={pwForm.data.current_password}
                                onChange={(e) => pwForm.setData('current_password', e.target.value)}
                                className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white"
                            />
                            {pwForm.errors.current_password && (
                                <p className="mt-1 text-xs text-rose-400">{pwForm.errors.current_password}</p>
                            )}
                        </div>
                        <div>
                            <label className="mb-1 block text-xs text-slate-400">New Password (min 10 chars)</label>
                            <input
                                type="password"
                                required
                                value={pwForm.data.password}
                                onChange={(e) => pwForm.setData('password', e.target.value)}
                                className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white"
                            />
                            {pwForm.errors.password && <p className="mt-1 text-xs text-rose-400">{pwForm.errors.password}</p>}
                        </div>
                        <div>
                            <label className="mb-1 block text-xs text-slate-400">Confirm New Password</label>
                            <input
                                type="password"
                                required
                                value={pwForm.data.password_confirmation}
                                onChange={(e) => pwForm.setData('password_confirmation', e.target.value)}
                                className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white"
                            />
                        </div>
                        <button
                            type="submit"
                            disabled={pwForm.processing}
                            className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500"
                        >
                            Update Password
                        </button>
                    </form>
                </section>

                <section className="rounded-xl border border-slate-800 bg-slate-900 p-6 space-y-4">
                    <div className="flex items-center justify-between">
                        <h2 className="text-base font-semibold text-white">Two-Factor Authentication (TOTP)</h2>
                        <span
                            className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${
                                user.two_factor_enabled
                                    ? 'bg-emerald-500/20 text-emerald-300'
                                    : 'bg-slate-800 text-slate-400'
                            }`}
                        >
                            {user.two_factor_enabled ? 'Enabled' : 'Disabled'}
                        </span>
                    </div>

                    {user.two_factor_enabled ? (
                        <button
                            type="button"
                            onClick={() => router.delete('/profile/2fa')}
                            className="rounded-lg border border-rose-500/40 bg-rose-950/40 px-4 py-2 text-sm text-rose-300 hover:bg-rose-900/40"
                        >
                            Disable Two-Factor Auth
                        </button>
                    ) : pendingSecret ? (
                        <div className="space-y-3">
                            <p className="text-xs text-slate-300">
                                Add this secret to your authenticator app (Google Authenticator, 1Password, Authy):
                            </p>
                            <code className="block rounded bg-slate-950 p-2 font-mono text-sm text-indigo-300">
                                {pendingSecret}
                            </code>
                            <p className="break-all text-[11px] text-slate-500">{pendingUri}</p>
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    otpForm.post('/profile/2fa/confirm');
                                }}
                                className="flex gap-2"
                            >
                                <input
                                    type="text"
                                    maxLength={6}
                                    required
                                    placeholder="6-digit code"
                                    value={otpForm.data.otp_code}
                                    onChange={(e) => otpForm.setData('otp_code', e.target.value)}
                                    className="w-36 rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-sm text-white"
                                />
                                <button
                                    type="submit"
                                    className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-500"
                                >
                                    Verify & Enable
                                </button>
                            </form>
                            {otpForm.errors.otp_code && <p className="text-xs text-rose-400">{otpForm.errors.otp_code}</p>}
                        </div>
                    ) : (
                        <button
                            type="button"
                            onClick={() => router.post('/profile/2fa/setup')}
                            className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500"
                        >
                            Set Up Two-Factor Auth
                        </button>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
