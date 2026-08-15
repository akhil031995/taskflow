<?php
/**
 * Live session monitoring UI (global): the "Live Claude Session" slide-over
 * drawer (right). The Active Sessions selector list lives in the sidebar
 * (includes/header.php). Both are populated by assets/session_monitor.js.
 */
?>

<!-- ===================== LIVE CLAUDE SESSION DRAWER ===================== -->
<div id="session-drawer"
     class="fixed top-0 right-0 z-50 h-full w-full max-w-xl translate-x-full transition-transform duration-300
            bg-slate-950 text-slate-200 border-l border-slate-800 shadow-2xl flex flex-col">
    <!-- Header -->
    <div class="flex items-center justify-between gap-2 px-4 h-14 border-b border-slate-800 bg-slate-900/60">
        <div class="flex items-center gap-2 min-w-0">
            <span>🤖</span>
            <div class="min-w-0">
                <p class="text-xs text-slate-400 leading-none">LIVE CLAUDE SESSION</p>
                <p id="drawer-title" class="text-sm font-semibold truncate">—</p>
            </div>
            <span id="drawer-badge" class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-emerald-500/15 text-emerald-300 ring-1 ring-emerald-500/30">RUNNING</span>
        </div>
        <div class="flex items-center gap-1 shrink-0">
            <span id="drawer-conn" class="text-[10px] text-slate-500" title="Stream status">●</span>
            <button id="drawer-close" class="px-2 py-1 rounded hover:bg-slate-800 text-slate-400" title="Close">✕</button>
        </div>
    </div>

    <div class="flex-1 overflow-y-auto">
        <!-- AI Conversation -->
        <section class="border-b border-slate-800">
            <div class="px-4 py-2 text-xs font-semibold text-slate-400 flex items-center justify-between">
                <span>AI Conversation</span>
            </div>
            <div id="conv-panel" class="px-4 pb-3 space-y-2 max-h-64 overflow-y-auto text-xs">
                <p class="text-slate-500">Waiting for the agent…</p>
            </div>
        </section>

        <!-- Live Code View -->
        <section class="border-b border-slate-800">
            <div class="px-4 py-2 text-xs font-semibold text-slate-400">Live Code View</div>
            <div id="code-tabs" class="flex gap-1 px-4 overflow-x-auto"></div>
            <pre id="code-body" class="mx-4 mb-3 mt-1 rounded-lg bg-black/50 border border-slate-800 p-3 text-[11px] leading-snug font-mono overflow-auto max-h-64"><span class="text-slate-500">No file changes yet.</span></pre>
        </section>

        <!-- Terminal Output -->
        <section>
            <div class="px-4 py-2 text-xs font-semibold text-slate-400">Terminal Output</div>
            <div id="term-panel" class="mx-4 mb-4 rounded-lg bg-black/70 border border-slate-800 p-3 font-mono text-[11px] leading-relaxed text-slate-300 h-56 overflow-y-auto">
                <div class="text-slate-500">[status] Ready.</div>
            </div>
        </section>
    </div>
</div>
