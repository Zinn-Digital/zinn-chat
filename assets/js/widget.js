/*!
 * Zinn® Chat widget — Neil Lock, CEO, Zinn Digital® Ltd — https://zinndigital.com — GPL-2.0-or-later.
 * Loaded only when a visitor opens the chat (see loader.js). No framework, no dependencies; its
 * styles live in the launcher's shadow root, so the theme cannot break it and it cannot leak.
 * Message text is always inserted as TEXT; only [title](https://…) links become <a> elements.
 */
(function () {
	'use strict';
	var d = document, w = window;
	var host = d.getElementById('zinn-chat-host');
	if (!host || host.zc) { return; }
	var c = host.zcConfig, root = host.zcRoot, S = null, t = {};
	var token = read('zinn_chat_token'), after = 0, status = 'bot', timer = 0, typingSent = 0, busy = false, proof = '';
	var el = {};

	function read(k) { try { return w.sessionStorage.getItem(k) || w.localStorage.getItem(k) || ''; } catch (e) { return ''; } }
	function write(k, v) { try { if (v) { w.sessionStorage.setItem(k, v); w.localStorage.setItem(k, v); } else { w.sessionStorage.removeItem(k); w.localStorage.removeItem(k); } } catch (e) {} }
	function h(tag, cls, text) { var n = d.createElement(tag); if (cls) { n.className = cls; } if (text) { n.textContent = text; } return n; }

	function api(path, body, cb) {
		var x = new XMLHttpRequest();
		x.open('POST', c.api + path, true);
		x.setRequestHeader('Content-Type', 'application/json');
		if (c.nonce) { x.setRequestHeader('X-WP-Nonce', c.nonce); }
		x.onload = function () {
			var r = null;
			try { r = JSON.parse(x.responseText); } catch (e) {}
			cb(r || { ok: false, message: t.error }, x.status);
		};
		x.onerror = function () { cb({ ok: false, message: t.error }, 0); };
		body = body || {};
		if (token) { body.token = token; }
		x.send(JSON.stringify(body));
	}

	var css = ':host{all:initial}*{box-sizing:border-box;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}'
		+ '.p{position:fixed;bottom:88px;inset-inline-end:20px;z-index:2147483001;width:370px;max-width:calc(100vw - 24px);height:560px;max-height:calc(100vh - 110px);background:#fff;color:#1f2328;border-radius:14px;box-shadow:0 12px 40px rgba(0,0,0,.25);display:flex;flex-direction:column;overflow:hidden;font-size:15px;line-height:1.45}'
		+ '.p[data-side=left]{inset-inline-end:auto;inset-inline-start:20px}'
		+ '.hd{background:var(--c);color:var(--t);padding:14px 16px;display:flex;align-items:center;gap:8px}.hd b{flex:1;font-size:16px}.hd small{display:block;font-weight:400;opacity:.9;font-size:12px}'
		+ '.ib{background:transparent;border:0;color:inherit;cursor:pointer;padding:6px;border-radius:6px;font-size:14px}.ib:focus-visible{outline:2px solid currentColor}'
		+ '.ms{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px;background:#f6f7f9}'
		+ '.m{max-width:85%;padding:9px 12px;border-radius:14px;white-space:pre-wrap;word-wrap:break-word;background:#fff;border:1px solid #e6e8eb;align-self:flex-start}'
		+ '.m.visitor{align-self:flex-end;background:var(--c);color:var(--t);border-color:transparent}.m.system{align-self:center;background:transparent;border:0;color:#57606a;font-size:13px;text-align:center}'
		+ '.m a{color:inherit;text-decoration:underline}.who{font-size:11px;color:#57606a;margin-bottom:2px}.src{margin-top:6px;font-size:12px;border-top:1px solid #e6e8eb;padding-top:6px}.src a{display:block;color:#0b57d0}'
		+ '.fb{display:flex;gap:6px;margin-top:6px;font-size:12px;align-items:center;color:#57606a}.fb button{border:1px solid #d0d7de;background:#fff;border-radius:12px;padding:2px 10px;cursor:pointer}'
		+ '.ty{font-size:12px;color:#57606a;padding:0 16px 6px;min-height:18px}'
		+ '.ac{display:flex;gap:6px;flex-wrap:wrap;padding:8px 12px 0}.ac button{border:1px solid var(--c);color:var(--c);background:#fff;border-radius:16px;padding:5px 12px;cursor:pointer;font-size:13px}'
		+ '.ft{padding:10px 12px;border-top:1px solid #e6e8eb;display:flex;gap:8px;align-items:flex-end}'
		+ '.ft textarea{flex:1;resize:none;border:1px solid #d0d7de;border-radius:10px;padding:9px 10px;font-size:15px;max-height:120px;min-height:40px;color:#1f2328;background:#fff}'
		+ '.go{background:var(--c);color:var(--t);border:0;border-radius:10px;padding:10px 14px;cursor:pointer;font-weight:600}.go[disabled]{opacity:.5;cursor:default}'
		+ '.cs{padding:8px 14px;font-size:12px;color:#57606a;border-top:1px solid #e6e8eb}.cs label{display:flex;gap:6px;align-items:flex-start}.cs a{color:#0b57d0}'
		+ '.fm{padding:14px;display:flex;flex-direction:column;gap:8px;overflow-y:auto}.fm input,.fm textarea{border:1px solid #d0d7de;border-radius:8px;padding:9px 10px;font-size:15px;width:100%}.fm .row{display:flex;gap:8px}'
		+ '.hp{position:absolute;inset-inline-start:-9999px;height:1px;overflow:hidden}.br{text-align:center;font-size:11px;padding:4px;color:#8c959f}.br a{color:inherit}'
		+ '.er{color:#b42318;font-size:13px}'
		+ '@media (max-width:600px){.p{inset:0!important;width:100%;max-width:none;height:100%;max-height:none;border-radius:0}}'
		+ '@media (prefers-reduced-motion:no-preference){.p{animation:zi .18s ease-out}}@keyframes zi{from{opacity:0;transform:translateY(8px)}to{opacity:1}}';

	function build() {
		var style = h('style'); style.textContent = css; root.appendChild(style);
		var p = el.panel = h('div', 'p');
		p.setAttribute('role', 'dialog');
		p.setAttribute('aria-modal', 'false');
		p.setAttribute('dir', d.documentElement.dir || 'ltr');
		p.setAttribute('lang', c.lang || '');
		p.setAttribute('data-side', c.side);
		p.style.setProperty('--c', c.colour);
		p.style.setProperty('--t', c.text);
		p.hidden = true;
		var hd = h('div', 'hd');
		el.title = h('b');
		el.sub = h('small');
		var tb = h('div'); tb.style.flex = '1'; tb.appendChild(el.title); tb.appendChild(el.sub); el.title.style.display = 'block';
		hd.appendChild(tb);
		el.menu = h('button', 'ib'); el.menu.type = 'button'; el.menu.textContent = '⋯';
		el.min = h('button', 'ib'); el.min.type = 'button'; el.min.textContent = '–';
		hd.appendChild(el.menu); hd.appendChild(el.min);
		p.appendChild(hd);
		el.msgs = h('div', 'ms'); el.msgs.setAttribute('aria-live', 'polite');
		p.appendChild(el.msgs);
		el.typing = h('div', 'ty'); p.appendChild(el.typing);
		el.actions = h('div', 'ac'); p.appendChild(el.actions);
		el.consent = h('div', 'cs'); p.appendChild(el.consent);
		el.foot = h('div', 'ft');
		el.input = h('textarea'); el.input.rows = 1;
		el.send = h('button', 'go'); el.send.type = 'button';
		el.foot.appendChild(el.input); el.foot.appendChild(el.send);
		p.appendChild(el.foot);
		el.form = h('div', 'fm'); el.form.hidden = true; p.appendChild(el.form);
		el.brand = h('div', 'br'); p.appendChild(el.brand);
		root.appendChild(p);

		el.min.addEventListener('click', close);
		el.menu.addEventListener('click', menu);
		el.send.addEventListener('click', send);
		el.input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
			else { typing(); }
		});
		p.addEventListener('keydown', function (e) { if (e.key === 'Escape') { close(); } });
	}

	function labels() {
		el.title.textContent = t.title;
		el.min.setAttribute('aria-label', t.minimise);
		el.menu.setAttribute('aria-label', t.transcript);
		el.send.textContent = t.send;
		el.input.setAttribute('aria-label', t.placeholder);
		el.input.placeholder = t.placeholder;
		el.input.maxLength = S.max || 2000;
		el.panel.setAttribute('aria-label', t.title);
		el.brand.textContent = '';
		if (S.branding) {
			var a = h('a', '', t.powered); a.href = 'https://zinndigital.com/wordpress-plugins/zinn-chat'; a.target = '_blank'; a.rel = 'noopener';
			el.brand.appendChild(a);
		}
		online();
		renderConsent();
	}

	function online() {
		el.sub.textContent = status === 'staff' ? (S.agent || t.team) : (S.ai ? t.bot : (S.online ? t.team : ''));
	}

	function renderConsent() {
		el.consent.textContent = '';
		el.consent.hidden = !(S.consent && !token);
		if (el.consent.hidden) { return; }
		var l = h('label'); var cb = h('input'); cb.type = 'checkbox'; el.agree = cb;
		var sp = h('span', '', t.consent + ' ');
		if (S.privacy) { var a = h('a', '', t.privacy); a.href = S.privacy; a.target = '_blank'; a.rel = 'noopener'; sp.appendChild(a); }
		l.appendChild(cb); l.appendChild(sp); el.consent.appendChild(l);
	}

	function linkify(parent, text) {
		var re = /\[([^\]\n]{1,300})\]\((https?:\/\/[^\s)]{1,700})\)|(https?:\/\/[^\s<>()]{4,700})/g, last = 0, m;
		while ((m = re.exec(text))) {
			parent.appendChild(d.createTextNode(text.slice(last, m.index)));
			var a = h('a', '', m[1] || m[3]); a.href = m[2] || m[3]; a.target = '_blank'; a.rel = 'noopener';
			parent.appendChild(a); last = re.lastIndex;
		}
		parent.appendChild(d.createTextNode(text.slice(last)));
	}

	function bubble(m) {
		if (root.querySelector('[data-id="' + m.id + '"]')) { return; }
		var b = h('div', 'm ' + m.role); b.setAttribute('data-id', m.id);
		if (m.role === 'ai' || m.role === 'agent') {
			var who = h('div', 'who', m.role === 'ai' ? t.bot : (m.name || t.team)); b.appendChild(who);
		}
		var body = h('div'); linkify(body, m.text || ''); b.appendChild(body);
		if (m.sources && m.sources.length) {
			var s = h('div', 'src', t.sources + ':');
			m.sources.forEach(function (x) { var a = h('a', '', x.title || x.url); a.href = x.url; a.target = '_blank'; a.rel = 'noopener'; s.appendChild(a); });
			b.appendChild(s);
		}
		if (m.role === 'ai') {
			var fb = h('div', 'fb', t.helpful);
			[['yes', 1], ['no', -1]].forEach(function (o) {
				var bt = h('button', '', t[o[0]]); bt.type = 'button';
				bt.addEventListener('click', function () { api('chat/rate', { rating: o[1] }, function () {}); fb.textContent = t.thanks; if (o[1] < 0) { actions(true); } });
				fb.appendChild(bt);
			});
			b.appendChild(fb);
		}
		el.msgs.appendChild(b);
	}

	function apply(r) {
		if (!r || !r.ok) { if (r && r.message) { note(r.message); } return; }
		if (r.token) { token = r.token; write('zinn_chat_token', token); renderConsent(); }
		(r.messages || []).forEach(function (m) { bubble(m); after = Math.max(after, m.id); });
		status = r.status || status;
		S.online = r.online; S.agent = r.agent;
		online();
		el.typing.textContent = r.agent_typing ? (r.agent || t.team) + ' ' + t.typing : '';
		actions(false, r.offer);
		el.msgs.scrollTop = el.msgs.scrollHeight;
		schedule();
	}

	function actions(force, offer) {
		el.actions.textContent = '';
		offer = offer || {};
		if ((offer.human || force) && S.human && S.online && status === 'bot') { button(t.human, human); }
		if ((offer.ticket || force) && S.tickets && status !== 'offline') { button(t.ticket, ticketForm); }
	}

	function button(label, fn) { var b = h('button', '', label); b.type = 'button'; b.addEventListener('click', fn); el.actions.appendChild(b); }
	function note(text) { bubble({ id: 'n' + Date.now(), role: 'system', text: text }); el.msgs.scrollTop = el.msgs.scrollHeight; }

	function schedule() {
		clearTimeout(timer);
		if (!token || status === 'closed') { return; }
		var live = status === 'waiting' || status === 'staff';
		var ms = el.panel.hidden ? (live ? 15000 : 0) : (live ? 3000 : 20000);
		if (ms) { timer = setTimeout(poll, d.hidden ? Math.max(ms, 15000) : ms); }
	}

	function poll() {
		api('chat/poll', { after: after }, function (r, code) {
			if (code === 404) { token = ''; write('zinn_chat_token', ''); return; }
			apply(r);
		});
	}

	function send() {
		var text = el.input.value.trim();
		if (!text || busy) { return; }
		if (S.consent && !token && el.agree && !el.agree.checked) { el.agree.focus(); note(t.consent); return; }
		if (S.challenge && !token && !proof) { challenge(send); return; }
		busy = true; el.send.disabled = true;
		bubble({ id: 'tmp', role: 'visitor', text: text });
		el.input.value = '';
		if (status === 'bot' && S.ai) { el.typing.textContent = t.thinking; }
		api('chat/message', { text: text, after: after, consent: el.agree ? el.agree.checked : true, page_url: location.href, language: navigator.language || '', proof: proof }, function (r) {
			busy = false; el.send.disabled = false;
			var tmp = root.querySelector('[data-id="tmp"]'); if (tmp && r && r.ok) { tmp.remove(); }
			el.typing.textContent = '';
			apply(r);
			el.input.focus();
		});
	}

	function typing() {
		if (!token || Date.now() - typingSent < 4000 || status === 'bot') { return; }
		typingSent = Date.now();
		api('chat/typing', {}, function () {});
	}

	function human() { api('chat/human', { after: after }, apply); }

	function field(label, type, name, value, big) {
		var i = h(big ? 'textarea' : 'input'); if (!big) { i.type = type; } else { i.rows = 4; }
		i.name = name; i.value = value || ''; i.placeholder = label; i.setAttribute('aria-label', label); i.required = true;
		el.form.appendChild(i); return i;
	}

	function ticketForm() {
		el.form.textContent = ''; el.form.hidden = false; el.msgs.hidden = true; el.foot.hidden = true; el.actions.hidden = true; el.consent.hidden = true;
		el.form.appendChild(h('p', '', S.online ? t.ticket : t.offline));
		var u = S.user || {};
		var n = field(t.name, 'text', 'name', u.name), e = field(t.email, 'email', 'email', u.email), m = field(t.message, 'text', 'text', '', true);
		var hp = h('input', 'hp'); hp.name = 'website'; hp.tabIndex = -1; hp.setAttribute('autocomplete', 'off'); hp.setAttribute('aria-hidden', 'true'); el.form.appendChild(hp);
		var err = h('div', 'er'); el.form.appendChild(err);
		var row = h('div', 'row'), ok = h('button', 'go', t.submit), no = h('button', 'ib', t.cancel);
		ok.type = no.type = 'button'; no.style.color = '#57606a';
		row.appendChild(ok); row.appendChild(no); el.form.appendChild(row);
		no.addEventListener('click', back);
		ok.addEventListener('click', function () {
			if (!e.value || !m.value && !token) { err.textContent = t.error; return; }
			ok.disabled = true;
			api('chat/ticket', { name: n.value, email: e.value, text: m.value, website: hp.value, after: after }, function (r) {
				ok.disabled = false;
				if (!r || !r.ok) { err.textContent = (r && r.message) || t.error; return; }
				back();
				if (r.ticket && !r.messages) { note(t.sent); } else { apply(r); }
			});
		});
		n.focus();
	}

	function back() { el.form.hidden = true; el.msgs.hidden = false; el.foot.hidden = false; el.actions.hidden = false; renderConsent(); }

	function menu() {
		el.form.textContent = ''; el.form.hidden = false; el.msgs.hidden = true; el.foot.hidden = true; el.actions.hidden = true; el.consent.hidden = true;
		if (token) {
			var e = field(t.email, 'email', 'email', (S.user || {}).email);
			var sendT = h('button', 'go', t.transcript); sendT.type = 'button'; el.form.appendChild(sendT);
			sendT.addEventListener('click', function () { api('chat/transcript', { email: e.value }, function (r) { if (r && r.ok) { back(); note(t.sent); } }); });
			var end = h('button', 'ib', t.end); end.type = 'button'; end.style.color = '#b42318'; el.form.appendChild(end);
			end.addEventListener('click', function () {
				api('chat/close', {}, function () {});
				token = ''; after = 0; status = 'bot'; write('zinn_chat_token', ''); el.msgs.textContent = ''; back(); greet();
			});
		}
		var cancel = h('button', 'ib', t.cancel); cancel.type = 'button'; cancel.style.color = '#57606a'; el.form.appendChild(cancel);
		cancel.addEventListener('click', back);
	}

	// A Pro human check (S.challenge: the script, its site key, and the global it defines).
	function challenge(then) {
		var c = S.challenge, box = h('div'); el.form.textContent = ''; el.form.hidden = false; el.form.appendChild(box);
		function render() {
			w[c.global].render(box, { sitekey: c.key, callback: function (v) { proof = v; el.form.hidden = true; then(); } });
		}
		if (w[c.global]) { render(); return; }
		var s = d.createElement('script'); s.src = c.src; s.async = true; s.onload = render; d.head.appendChild(s);
	}

	function greet() {
		if (token) { return; }
		note(S.ai || S.online ? t.greeting : t.offline);
		if (!S.ai && !S.online && S.tickets) { actions(true); }
	}

	function small() { return w.matchMedia && w.matchMedia('(max-width: 600px)').matches; }

	function open() {
		el.panel.hidden = false;
		var launcher = host.zcRoot.querySelector('.l');
		launcher.setAttribute('aria-expanded', 'true');
		// Full screen on a phone: the launcher would sit on top of the send button.
		launcher.style.display = small() ? 'none' : '';
		el.input.focus();
		if (token) { poll(); } else { schedule(); }
	}

	function close() {
		el.panel.hidden = true;
		var l = host.zcRoot.querySelector('.l'); l.style.display = ''; l.setAttribute('aria-expanded', 'false'); l.focus();
		schedule();
	}

	host.zc = { open: function () { if (el.panel.hidden) { open(); } else { close(); } }, close: close };
	build();
	api('chat/status', {}, function (r) {
		if (!r || !r.ok) { el.panel.hidden = false; note((r && r.message) || 'Chat unavailable.'); return; }
		S = r; t = r.i18n;
		labels();
		greet();
		if (token) { poll(); }
		if (host.zcOpen) { open(); }
	});
	d.addEventListener('visibilitychange', schedule);
}());
