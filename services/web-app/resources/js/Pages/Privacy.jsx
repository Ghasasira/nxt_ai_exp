import { Head, Link } from '@inertiajs/react';

export default function Privacy() {
    return (
        <div className="min-h-screen bg-slate-950 p-6 text-slate-200">
            <Head title="Data Handling & Privacy Notice" />
            <div className="mx-auto max-w-3xl rounded-2xl border border-slate-800 bg-slate-900 p-8 space-y-5">
                <div className="flex items-center justify-between border-b border-slate-800 pb-4">
                    <div>
                        <h1 className="text-xl font-semibold text-white">Data Handling &amp; Privacy Notice</h1>
                        <p className="text-xs text-slate-400">Uganda Data Protection and Privacy Act, 2019 alignment</p>
                    </div>
                    <Link href="/chat" className="rounded-lg bg-slate-800 px-3 py-1.5 text-xs text-white hover:bg-slate-700">
                        Back to App
                    </Link>
                </div>

                <section className="space-y-2 text-sm">
                    <h2 className="font-semibold text-indigo-300">1. On-Premises Storage &amp; Indexing</h2>
                    <p className="text-slate-300">
                        Uploaded source documents, extracted chunks, Qdrant vector indices, local embeddings (nomic-embed-text),
                        user accounts, and audit logs are stored on the organisation&apos;s on-premises Mac Pro server.
                    </p>
                </section>

                <section className="space-y-2 text-sm">
                    <h2 className="font-semibold text-indigo-300">2. Cloud Answer Generation (Google Gemini)</h2>
                    <p className="text-slate-300">
                        For standard collections, your question and the top 4–6 retrieved document snippets are transmitted over
                        TLS to the Google Gemini API solely to generate a cited answer. Collections marked{' '}
                        <span className="font-medium text-amber-300">Retrieval-Only</span> never send passages or queries to
                        external cloud services.
                    </p>
                </section>

                <section className="space-y-2 text-sm">
                    <h2 className="font-semibold text-indigo-300">3. Retention &amp; Auditability</h2>
                    <p className="text-slate-300">
                        Query logs follow the administrator-configured retention policy and can be redacted while preserving
                        anonymized usage and cost metrics.
                    </p>
                </section>
            </div>
        </div>
    );
}
