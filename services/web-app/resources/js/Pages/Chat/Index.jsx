import { Head, Link } from '@inertiajs/react';
import { FileText, Plus, Send, ThumbsDown, ThumbsUp, Trash2 } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';

export default function ChatIndex({ collections, conversations: initialConversations }) {
    const [conversations, setConversations] = useState(initialConversations || []);
    const [activeConvId, setActiveConvId] = useState(null);
    const [messages, setMessages] = useState([]);
    const [selectedCols, setSelectedCols] = useState([]);
    const [query, setQuery] = useState('');
    const [streaming, setStreaming] = useState(false);
    const [feedbackSent, setFeedbackSent] = useState({});

    const toggleCollection = (id) => {
        setSelectedCols((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
    };

    const loadConversation = async (id) => {
        const res = await fetch(`/api/v1/conversations/${id}`, { credentials: 'same-origin' });
        if (res.ok) {
            const data = await res.json();
            setActiveConvId(id);
            setMessages(data.messages || []);
        }
    };

    const deleteConversation = async (id) => {
        const res = await fetch(`/api/v1/conversations/${id}`, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
        });
        if (res.ok) {
            setConversations((prev) => prev.filter((c) => c.id !== id));
            if (activeConvId === id) {
                setActiveConvId(null);
                setMessages([]);
            }
        }
    };

    const sendFeedback = async (requestId, rating) => {
        const comment = rating === -1 ? window.prompt('Optional note on what was wrong:') || '' : '';
        const res = await fetch('/api/v1/feedback', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ request_id: requestId, rating, comment }),
        });
        if (res.ok) {
            setFeedbackSent((prev) => ({ ...prev, [requestId]: rating }));
        }
    };

    const submitQuestion = async (e) => {
        e.preventDefault();
        if (!query.trim() || streaming) return;

        const userText = query.trim();
        setQuery('');
        setStreaming(true);

        const assistantPlaceholder = { id: `tmp-${Date.now()}`, role: 'assistant', content: '', citations: [], mode: 'streaming' };
        setMessages((prev) => [...prev, { id: `u-${Date.now()}`, role: 'user', content: userText }, assistantPlaceholder]);

        try {
            const res = await fetch('/api/v1/chat', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'text/event-stream' },
                body: JSON.stringify({
                    query: userText,
                    conversation_id: activeConvId,
                    collection_ids: selectedCols.length > 0 ? selectedCols : null,
                    stream: true,
                }),
            });

            if (!res.ok || !res.body) {
                const err = await res.json().catch(() => ({ message: 'Request failed' }));
                setMessages((prev) => [
                    ...prev.slice(0, -1),
                    { ...assistantPlaceholder, content: err.message || 'Failed to generate answer.', mode: 'error' },
                ]);
                return;
            }

            const reader = res.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            while (true) {
                const { value, done } = await reader.read();
                if (done) break;
                buffer += decoder.decode(value, { stream: true });
                const frames = buffer.split('\n\n');
                buffer = frames.pop() || '';

                for (const frame of frames) {
                    const eventMatch = frame.match(/^event:\s*(.+)$/m);
                    const dataMatch = frame.match(/^data:\s*(.+)$/m);
                    if (!dataMatch) continue;
                    const eventType = eventMatch ? eventMatch[1].trim() : 'message';
                    const payload = JSON.parse(dataMatch[1]);

                    if (eventType === 'token') {
                        setMessages((prev) => {
                            const updated = [...prev];
                            const last = updated[updated.length - 1];
                            updated[updated.length - 1] = { ...last, content: last.content + payload.delta };
                            return updated;
                        });
                    } else if (eventType === 'done') {
                        if (payload.conversation_id && !activeConvId) {
                            setActiveConvId(payload.conversation_id);
                            setConversations((prev) => [
                                { id: payload.conversation_id, title: userText.slice(0, 80), updated_at: new Date().toISOString() },
                                ...prev,
                            ]);
                        }
                        setMessages((prev) => {
                            const updated = [...prev];
                            updated[updated.length - 1] = {
                                id: payload.request_id,
                                request_id: payload.request_id,
                                role: 'assistant',
                                content: payload.answer,
                                citations: payload.citations || [],
                                mode: payload.mode,
                                usage: payload.usage,
                            };
                            return updated;
                        });
                    }
                }
            }
        } finally {
            setStreaming(false);
        }
    };

    return (
        <AppLayout title="Knowledge Chat">
            <Head title="Chat" />
            <div className="grid h-[calc(100vh-8rem)] gap-4 lg:grid-cols-4">
                <div className="flex flex-col rounded-xl border border-slate-800 bg-slate-900 p-3">
                    <button
                        type="button"
                        onClick={() => {
                            setActiveConvId(null);
                            setMessages([]);
                        }}
                        className="mb-3 flex items-center justify-center gap-2 rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500"
                    >
                        <Plus className="h-4 w-4" /> New Conversation
                    </button>
                    <div className="flex-1 space-y-1 overflow-y-auto">
                        {conversations.map((c) => (
                            <div
                                key={c.id}
                                className={`group flex items-center justify-between rounded-lg px-2.5 py-2 text-xs ${
                                    activeConvId === c.id ? 'bg-slate-800 text-white' : 'text-slate-400 hover:bg-slate-800/60'
                                }`}
                            >
                                <button type="button" onClick={() => loadConversation(c.id)} className="truncate text-left flex-1">
                                    {c.title || 'Untitled chat'}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => deleteConversation(c.id)}
                                    className="opacity-0 group-hover:opacity-100 text-slate-500 hover:text-rose-400"
                                >
                                    <Trash2 className="h-3.5 w-3.5" />
                                </button>
                            </div>
                        ))}
                    </div>
                </div>

                <div className="flex flex-col rounded-xl border border-slate-800 bg-slate-900 lg:col-span-3">
                    <div className="flex flex-wrap items-center gap-2 border-b border-slate-800 px-4 py-2.5">
                        <span className="text-xs text-slate-400">Collections:</span>
                        {collections.map((col) => {
                            const selected = selectedCols.includes(col.id);
                            return (
                                <button
                                    key={col.id}
                                    type="button"
                                    onClick={() => toggleCollection(col.id)}
                                    className={`rounded-full px-2.5 py-1 text-xs transition ${
                                        selected
                                            ? 'bg-indigo-600 text-white'
                                            : 'border border-slate-700 bg-slate-950 text-slate-300 hover:border-slate-500'
                                    }`}
                                >
                                    {col.name}
                                    {!col.allow_cloud_generation && ' (Local Only)'}
                                </button>
                            );
                        })}
                    </div>

                    <div className="flex-1 space-y-4 overflow-y-auto p-4">
                        {messages.length === 0 && (
                            <div className="flex h-full items-center justify-center text-sm text-slate-500">
                                Ask a question grounded in your organisation&apos;s documents.
                            </div>
                        )}
                        {messages.map((msg) => (
                            <div key={msg.id} className={`flex ${msg.role === 'user' ? 'justify-end' : 'justify-start'}`}>
                                <div
                                    className={`max-w-3xl rounded-xl px-4 py-3 text-sm ${
                                        msg.role === 'user'
                                            ? 'bg-indigo-600 text-white'
                                            : 'border border-slate-800 bg-slate-950 text-slate-100'
                                    }`}
                                >
                                    <div className="whitespace-pre-wrap leading-relaxed">{msg.content || '...'}</div>

                                    {msg.citations && msg.citations.length > 0 && (
                                        <div className="mt-3 space-y-1.5 border-t border-slate-800 pt-2.5">
                                            <div className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">
                                                Sources
                                            </div>
                                            {msg.citations.map((cit, idx) => (
                                                <div key={`${cit.chunk_id}-${idx}`} className="rounded bg-slate-900 p-2 text-xs">
                                                    <div className="flex items-center justify-between text-indigo-300">
                                                        <Link href={`/documents/${cit.document_id}`} className="flex items-center gap-1 hover:underline">
                                                            <FileText className="h-3.5 w-3.5" />
                                                            <span>{cit.title}</span>
                                                        </Link>
                                                        <span className="text-slate-400">
                                                            {cit.page ? `p. ${cit.page}` : cit.heading_path}
                                                        </span>
                                                    </div>
                                                    <p className="mt-1 text-slate-400 line-clamp-2">{cit.snippet}</p>
                                                </div>
                                            ))}
                                        </div>
                                    )}

                                    {msg.role === 'assistant' && msg.request_id && (
                                        <div className="mt-2 flex items-center justify-between text-xs text-slate-500">
                                            <span>
                                                {msg.mode} {msg.usage ? `· ${msg.usage.latency_ms} ms` : ''}
                                            </span>
                                            <div className="flex items-center gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() => sendFeedback(msg.request_id, 1)}
                                                    className={`hover:text-emerald-400 ${
                                                        feedbackSent[msg.request_id] === 1 ? 'text-emerald-400' : ''
                                                    }`}
                                                >
                                                    <ThumbsUp className="h-3.5 w-3.5" />
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => sendFeedback(msg.request_id, -1)}
                                                    className={`hover:text-rose-400 ${
                                                        feedbackSent[msg.request_id] === -1 ? 'text-rose-400' : ''
                                                    }`}
                                                >
                                                    <ThumbsDown className="h-3.5 w-3.5" />
                                                </button>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>

                    <form onSubmit={submitQuestion} className="flex gap-2 border-t border-slate-800 p-3">
                        <input
                            type="text"
                            maxLength={2000}
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder="Ask a question about your documents..."
                            className="flex-1 rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white focus:border-indigo-500 focus:outline-none"
                        />
                        <button
                            type="submit"
                            disabled={streaming || !query.trim()}
                            className="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500 disabled:opacity-50"
                        >
                            <Send className="h-4 w-4" />
                            <span>{streaming ? 'Streaming...' : 'Ask'}</span>
                        </button>
                    </form>
                </div>
            </div>
        </AppLayout>
    );
}
