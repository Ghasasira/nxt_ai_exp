import { Head, Link, router, useForm } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';

export default function Login({ twoFactorPending }) {
    const form = useForm({
        email: '',
        password: '',
        remember: false,
        otp_code: '',
    });

    const submit = (e) => {
        e.preventDefault();
        form.post('/login');
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-950 p-4">
            <Head title="Sign In" />
            <div className="w-full max-w-md rounded-2xl border border-slate-800 bg-slate-900 p-8 shadow-xl">
                <div className="mb-6 flex items-center gap-3">
                    <ShieldCheck className="h-8 w-8 text-indigo-400" />
                    <div>
                        <h1 className="text-xl font-semibold text-white">RAG Knowledge Agent</h1>
                        <p className="text-xs text-slate-400">Sign in to ask questions or manage collections</p>
                    </div>
                </div>

                <form onSubmit={submit} className="space-y-4">
                    {!twoFactorPending ? (
                        <>
                            <div>
                                <label className="mb-1 block text-xs font-medium text-slate-300">Email</label>
                                <input
                                    type="email"
                                    required
                                    value={form.data.email}
                                    onChange={(e) => form.setData('email', e.target.value)}
                                    className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white focus:border-indigo-500 focus:outline-none"
                                    placeholder="you@organisation.org"
                                />
                                {form.errors.email && <p className="mt-1 text-xs text-rose-400">{form.errors.email}</p>}
                            </div>
                            <div>
                                <label className="mb-1 block text-xs font-medium text-slate-300">Password</label>
                                <input
                                    type="password"
                                    required
                                    value={form.data.password}
                                    onChange={(e) => form.setData('password', e.target.value)}
                                    className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white focus:border-indigo-500 focus:outline-none"
                                />
                            </div>
                            <label className="flex items-center gap-2 text-xs text-slate-300">
                                <input
                                    type="checkbox"
                                    checked={form.data.remember}
                                    onChange={(e) => form.setData('remember', e.target.checked)}
                                    className="rounded border-slate-700 bg-slate-950"
                                />
                                Remember this session
                            </label>
                        </>
                    ) : (
                        <div>
                            <label className="mb-1 block text-xs font-medium text-slate-300">
                                Two-Factor Authentication Code (6 digits)
                            </label>
                            <input
                                type="text"
                                inputMode="numeric"
                                maxLength={6}
                                required
                                autoFocus
                                value={form.data.otp_code}
                                onChange={(e) => form.setData('otp_code', e.target.value)}
                                className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-center font-mono text-lg tracking-widest text-white focus:border-indigo-500 focus:outline-none"
                                placeholder="123456"
                            />
                            {form.errors.otp_code && <p className="mt-1 text-xs text-rose-400">{form.errors.otp_code}</p>}
                            <button
                                type="button"
                                onClick={() => router.post('/login/2fa-cancel')}
                                className="mt-2 text-xs text-slate-400 hover:text-white"
                            >
                                Cancel and switch account
                            </button>
                        </div>
                    )}

                    <button
                        type="submit"
                        disabled={form.processing}
                        className="w-full rounded-lg bg-indigo-600 py-2.5 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                    >
                        {twoFactorPending ? 'Verify Code' : 'Sign In'}
                    </button>
                </form>
                <div className="mt-4 text-center">
                    <Link href="/privacy" className="text-xs text-slate-500 hover:text-slate-300">
                        Data Handling &amp; Privacy Notice
                    </Link>
                </div>
            </div>
        </div>
    );
}
