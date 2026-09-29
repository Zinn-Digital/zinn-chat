function (c) {
	/*
	 * Zinn® Chat launcher — Neil Lock, CEO, Zinn Digital® Ltd — GPL-2.0-or-later.
	 * Printed INLINE by the plugin: it makes no request. It draws one fixed button inside a shadow
	 * root once the browser is idle (never the LCP element, no layout shift) and fetches the chat
	 * (widget.js) only when a visitor opens it, or when they already have a chat running.
	 */
	var d = document, w = window, host, root, btn, loading = 0, hex = /^#[0-9a-f]{6}$/i;
	if (!hex.test(c.colour)) { c.colour = '#1f6feb'; }
	if (!hex.test(c.text)) { c.text = '#ffffff'; }
	function store(k) { try { return w.sessionStorage.getItem(k) || w.localStorage.getItem(k); } catch (e) { return null; } }
	function load(open) {
		if (host.zc) { if (open) { host.zc.open(); } return; }
		host.zcOpen = host.zcOpen || open;
		if (loading) { return; }
		loading = 1;
		var s = d.createElement('script');
		s.src = c.src; s.async = true;
		d.head.appendChild(s);
	}
	function draw() {
		if (host) { return; }
		host = d.createElement('div');
		host.id = 'zinn-chat-host';
		host.zcConfig = c;
		root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
		host.zcRoot = root;
		// Logical sides: "end" is the right in a left-to-right page and the left in a right-to-left one.
		var side = c.side === 'left' ? 'inset-inline-start' : 'inset-inline-end';
		root.innerHTML = '<style>:host{all:initial}.l{position:fixed;bottom:20px;' + side + ':20px;z-index:2147483000;width:56px;height:56px;border:0;border-radius:50%;cursor:pointer;background:' + c.colour + ';color:' + c.text + ';box-shadow:0 4px 14px rgba(0,0,0,.25);display:flex;align-items:center;justify-content:center;padding:0}.l:focus-visible{outline:3px solid ' + c.colour + ';outline-offset:3px}.l svg{width:28px;height:28px}</style>'
			+ '<button class="l" type="button" part="launcher" aria-haspopup="dialog"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H9l-5 4v-4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/></svg></button>';
		btn = root.querySelector('button');
		btn.setAttribute('aria-label', c.i18n.open);
		btn.addEventListener('click', function () { load(1); });
		d.body.appendChild(host);
		if (store('zinn_chat_token')) { load(0); }
	}
	// Anything on the page can open the chat: a menu item linking to #zinn-chat, or class "zinn-chat-open".
	d.addEventListener('click', function (e) {
		var t = e.target && e.target.closest ? e.target.closest('a[href$="#zinn-chat"],.zinn-chat-open') : null;
		if (t) { e.preventDefault(); draw(); load(1); }
	});
	function start() { (w.requestIdleCallback || function (f) { setTimeout(f, 1500); })(draw, { timeout: 4000 }); }
	if (d.readyState === 'complete') { start(); } else { w.addEventListener('load', start); }
}
