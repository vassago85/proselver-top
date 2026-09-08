<style>
    /*
     * Owner-wall design tokens shared between the Owner Command Centre
     * and the Operations Command Centre. Anything else that wants the
     * "same feel" pulls in this partial and wraps its content in
     * `<div class="owner-wall">`.
     *
     * Colours are semantic — status roles never double as a series
     * hue. Slate for chrome, cyan/emerald for series data, rose/amber
     * for severity.
     */
    .owner-wall .ow-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 1px 2px rgba(15,23,42,.04)}
    .owner-wall .ow-card:hover{border-color:#cbd5e1}
    .owner-wall .ow-label{font-size:10px;letter-spacing:.14em;text-transform:uppercase;color:#64748b;font-weight:700}
    .owner-wall .ow-hero{font-size:clamp(2.1rem,3.2vw,3.1rem);line-height:1;font-weight:780;letter-spacing:-.02em;font-variant-numeric:tabular-nums;color:#0f172a}
    .owner-wall .ow-bar{animation:ow-grow .7s ease-out both}
    @keyframes ow-grow{from{transform:scaleY(0);transform-origin:bottom}to{transform:scaleY(1);transform-origin:bottom}}
    @keyframes ow-pulse{0%,100%{opacity:1}50%{opacity:.45}}
    .owner-wall .ow-alert-pulse{animation:ow-pulse 1.6s ease-in-out infinite}

    /* Ops-specific: a "live" chip that pulses when auto-refresh is on. */
    .owner-wall .ow-live-dot{width:6px;height:6px;border-radius:9999px;background:#10b981;box-shadow:0 0 0 0 rgba(16,185,129,.6);animation:ow-live 1.8s ease-in-out infinite}
    @keyframes ow-live{0%,100%{box-shadow:0 0 0 0 rgba(16,185,129,.6)}50%{box-shadow:0 0 0 6px rgba(16,185,129,0)}}
</style>
