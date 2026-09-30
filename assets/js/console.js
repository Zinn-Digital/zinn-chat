/*!
 * Zinn® Chat agent console: the multi-chat Inbox, Tickets and the Assistant test console.
 * Neil Lock — CEO, Zinn Digital® Ltd — GPL-2.0-or-later. No build step: wp.apiFetch + wp.i18n.
 *
 * The Inbox is built so several chats can run at once without losing track: every open chat is a
 * tab that keeps its own draft and scroll position, waiting visitors sort to the top with how long
 * they have waited, unread counts show on each tab and in the list, and on a wide screen up to
 * three chats sit side by side. On a phone or tablet it becomes a single "focus" view: the list,
 * then one chat full screen with a back button.
 * All customer text is inserted as TEXT; only [title](https://…) links become <a> elements.
 */
(function (wp, cfg) {
	'use strict';
	var app = document.getElementById('zinn-chat-app');
	if (!app || !wp || !wp.apiFetch) { return; }
	var __ = wp.i18n.__, sprintf = wp.i18n.sprintf;
	var api = function (path, opts) { return wp.apiFetch(Object.assign({ path: '/zinn-chat/v1/' + path }, opts || {})); };
	var post = function (path, data) { return api(path, { method: 'POST', data: data || {} }); };

	function h(tag, cls, text) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined && text !== null) { n.textContent = String(text); } return n; }
	function btn(label, cls, fn) { var b = h('button', 'button ' + (cls || ''), label); b.type = 'button'; if (fn) { b.addEventListener('click', fn); } return b; }
	function linkify(parent, text) {
		var re = /\[([^\]\n]{1,300})\]\((https?:\/\/[^\s)]{1,700})\)|(https?:\/\/[^\s<>()]{4,700})/g, last = 0, m;
		text = text || '';
		while ((m = re.exec(text))) {
			parent.appendChild(document.createTextNode(text.slice(last, m.index)));
			var a = h('a', '', m[1] || m[3]); a.href = m[2] || m[3]; a.target = '_blank'; a.rel = 'noopener';
			parent.appendChild(a); last = re.lastIndex;
		}
		parent.appendChild(document.createTextNode(text.slice(last)));
	}
	function ago(iso) {
		if (!iso) { return ''; }
		var s = Math.max(0, Math.round((Date.now() - Date.parse(iso)) / 1000));
		/* translators: %d: seconds (a short "time ago", e.g. 45s) */
		if (s < 60) { return sprintf(__('%ds', 'zinn-chat'), s); }
		/* translators: %d: minutes (a short "time ago", e.g. 5m) */
		if (s < 3600) { return sprintf(__('%dm', 'zinn-chat'), Math.floor(s / 60)); }
		/* translators: %d: hours (a short "time ago", e.g. 3h) */
		if (s < 86400) { return sprintf(__('%dh', 'zinn-chat'), Math.floor(s / 3600)); }
		return new Date(iso).toLocaleDateString();
	}
	function when(iso) { return iso ? new Date(iso).toLocaleString() : ''; }
	// Extension events for add-ons (Pro), fired on `document`; they change nothing on their own.
	// `detail.insert(text)` puts text into that reply box at the cursor.
	function emit(name, detail) { try { document.dispatchEvent(new CustomEvent(name, { detail: detail })); } catch (e) {} }
	function inserter(input) {
		return function (text) {
			var a = input.selectionStart || 0, b = input.selectionEnd || 0;
			input.value = input.value.slice(0, a) + text + input.value.slice(b);
			input.focus(); input.selectionStart = input.selectionEnd = a + text.length;
		};
	}
	function fail(e) { window.alert((e && e.message) || __('Something went wrong. Please try again.', 'zinn-chat')); }
	var roleName = { visitor: __('Visitor', 'zinn-chat'), ai: __('Assistant', 'zinn-chat'), agent: __('Agent', 'zinn-chat'), system: '', note: __('Internal note', 'zinn-chat') };
	var statusName = { bot: __('With the assistant', 'zinn-chat'), waiting: __('Waiting for a person', 'zinn-chat'), staff: __('With an agent', 'zinn-chat'), offline: __('Left a message', 'zinn-chat'), closed: __('Closed', 'zinn-chat') };

	/* ─────────────────────────── INBOX ─────────────────────────── */
	function inbox() {
		var state = { rows: [], open: [], active: null, view: 'active', away: false, seen: {}, panes: {}, known: null };
		app.textContent = '';
		var root = h('div', 'zc-inbox');
		var bar = h('div', 'zc-bar');
		var title = h('h1', 'zc-title', __('Inbox', 'zinn-chat'));
		var presence = h('label', 'zc-presence');
		var awayBox = h('input'); awayBox.type = 'checkbox';
		presence.appendChild(awayBox); presence.appendChild(document.createTextNode(' ' + __('Away', 'zinn-chat')));
		var online = h('span', 'zc-online');
		var focus = btn(__('Focus mode', 'zinn-chat'), 'zc-focus-btn', function () { document.body.classList.toggle('zc-focus'); });
		var notify = btn(__('Desktop alerts', 'zinn-chat'), '', function () { if (window.Notification) { Notification.requestPermission(); } });
		bar.appendChild(title); bar.appendChild(online); bar.appendChild(presence); bar.appendChild(notify); bar.appendChild(focus);
		root.appendChild(bar);
		var body = h('div', 'zc-body');
		var side = h('aside', 'zc-list');
		var filters = h('div', 'zc-filters');
		[['active', __('Needs a person', 'zinn-chat')], ['bot', __('With the assistant', 'zinn-chat')], ['closed', __('Closed', 'zinn-chat')]].forEach(function (f) {
			var b = btn(f[1], f[0] === state.view ? 'is-active' : '', function () { state.view = f[0]; Array.prototype.forEach.call(filters.children, function (c) { c.classList.remove('is-active'); }); b.classList.add('is-active'); refresh(); });
			filters.appendChild(b);
		});
		side.appendChild(filters);
		var list = h('ul', 'zc-rows'); side.appendChild(list);
		var work = h('section', 'zc-work');
		var tabs = h('div', 'zc-tabs'); tabs.setAttribute('role', 'tablist');
		var panes = h('div', 'zc-panes');
		var empty = h('p', 'zc-empty', __('Choose a chat on the left. You can open several; each keeps its own draft.', 'zinn-chat'));
		panes.appendChild(empty);
		work.appendChild(tabs); work.appendChild(panes);
		body.appendChild(side); body.appendChild(work);
		root.appendChild(body);
		app.appendChild(root);

		awayBox.addEventListener('change', function () { state.away = awayBox.checked; post('agent/presence', { away: state.away }); });

		function beep() {
			try {
				var ctx = new (window.AudioContext || window.webkitAudioContext)(), o = ctx.createOscillator(), g = ctx.createGain();
				o.frequency.value = 880; g.gain.value = 0.08; o.connect(g); g.connect(ctx.destination); o.start(); o.stop(ctx.currentTime + 0.18);
			} catch (e) {}
		}

		function alertNew(rows) {
			var waiting = rows.filter(function (r) { return r.status === 'waiting'; }).map(function (r) { return r.id; });
			if (state.known !== null) {
				waiting.forEach(function (id) {
					if (state.known.indexOf(id) === -1) {
						beep();
						if (window.Notification && Notification.permission === 'granted') { new Notification(__('A visitor is waiting', 'zinn-chat'), { body: sprintf(/* translators: %d: chat number */ __('Chat #%d', 'zinn-chat'), id) }); }
					}
				});
			}
			state.known = waiting;
		}

		function renderList() {
			list.textContent = '';
			if (!state.rows.length) { list.appendChild(h('li', 'zc-none', __('Nothing here right now.', 'zinn-chat'))); }
			state.rows.forEach(function (r) {
				var li = h('li', 'zc-row zc-' + r.status + (state.active === r.id ? ' is-current' : ''));
				li.tabIndex = 0;
				var top = h('div', 'zc-row-top');
				/* translators: %d: visitor number */
				top.appendChild(h('strong', '', r.name || r.email || sprintf(__('Visitor #%d', 'zinn-chat'), r.id)));
				if (r.unread) { top.appendChild(h('span', 'zc-badge', r.unread)); }
				li.appendChild(top);
				li.appendChild(h('div', 'zc-row-last', (r.visitor_typing ? __('typing…', 'zinn-chat') : r.last) || ''));
				/* translators: %s: how long the visitor has waited, e.g. 2m */
				var meta = h('div', 'zc-row-meta', statusName[r.status] + (r.status === 'waiting' && r.waiting_since ? ' · ' + sprintf(__('waiting %s', 'zinn-chat'), ago(r.waiting_since)) : ' · ' + ago(r.updated_at)) + (r.agent ? ' · ' + r.agent : '') + (r.source_site ? ' · ' + r.source_site : ''));
				li.appendChild(meta);
				li.addEventListener('click', function () { openChat(r.id); });
				li.addEventListener('keydown', function (e) { if (e.key === 'Enter') { openChat(r.id); } });
				list.appendChild(li);
				emit('zinn-chat:row', { row: r, node: li });
			});
		}

		function refresh() {
			return api('agent/inbox?view=' + state.view).then(function (r) {
				state.rows = r.rows || [];
				alertNew(state.rows);
				awayBox.checked = !!r.away;
				/* translators: %d: number of team members online */
				online.textContent = sprintf(__('%d online', 'zinn-chat'), (r.online || []).length);
				renderList();
				state.open.forEach(function (id) {
					var row = state.rows.filter(function (x) { return x.id === id; })[0];
					var tab = tabs.querySelector('[data-id="' + id + '"] .zc-badge');
					if (tab) { tab.textContent = row && row.unread && id !== state.active ? row.unread : ''; }
				});
			}).catch(function () {});
		}

		function openChat(id) {
			if (state.open.indexOf(id) === -1) {
				state.open.push(id);
				var t = h('div', 'zc-tab'); t.setAttribute('role', 'tab'); t.setAttribute('data-id', id);
				var label = h('button', 'zc-tab-label', '#' + id); label.type = 'button';
				label.addEventListener('click', function () { activate(id); });
				var badge = h('span', 'zc-badge');
				var x = h('button', 'zc-tab-x', '×'); x.type = 'button'; x.setAttribute('aria-label', __('Close tab', 'zinn-chat'));
				x.addEventListener('click', function () { closeTab(id); });
				t.appendChild(label); t.appendChild(badge); t.appendChild(x);
				tabs.appendChild(t);
				state.panes[id] = pane(id, label);
				panes.appendChild(state.panes[id].el);
			}
			activate(id);
		}

		function activate(id) {
			state.active = id;
			empty.hidden = true;
			root.classList.add('has-chat');
			Array.prototype.forEach.call(tabs.children, function (t) { t.classList.toggle('is-active', +t.getAttribute('data-id') === id); });
			// Side by side on a wide screen: the active chat plus the two most recent others.
			var visible = [id].concat(state.open.filter(function (o) { return o !== id; }).slice(-2));
			Object.keys(state.panes).forEach(function (k) { state.panes[k].el.hidden = visible.indexOf(+k) === -1; state.panes[k].el.classList.toggle('is-active', +k === id); });
			panes.setAttribute('data-count', Math.min(visible.length, 3));
			state.panes[id].load(true);
			state.panes[id].input.focus();
			renderList();
		}

		function closeTab(id) {
			state.open = state.open.filter(function (o) { return o !== id; });
			var t = tabs.querySelector('[data-id="' + id + '"]'); if (t) { t.remove(); }
			if (state.panes[id]) { state.panes[id].stop(); state.panes[id].el.remove(); delete state.panes[id]; emit('zinn-chat:pane-close', { id: id }); }
			if (state.open.length) { activate(state.open[state.open.length - 1]); } else { state.active = null; empty.hidden = false; root.classList.remove('has-chat'); renderList(); }
		}

		function pane(id, tabLabel) {
			var p = { after: 0, timer: 0, data: null };
			var el = p.el = h('article', 'zc-pane');
			var head = h('header', 'zc-pane-head');
			var back = btn('←', 'zc-back', function () { root.classList.remove('has-chat'); });
			back.setAttribute('aria-label', __('Back to the list', 'zinn-chat'));
			var who = h('div', 'zc-who');
			var actions = h('div', 'zc-actions');
			head.appendChild(back); head.appendChild(who); head.appendChild(actions);
			var info = h('div', 'zc-info');
			var msgs = h('div', 'zc-msgs'); msgs.setAttribute('aria-live', 'polite');
			var typing = h('div', 'zc-typing');
			var form = h('div', 'zc-reply');
			var input = p.input = h('textarea'); input.rows = 2; input.placeholder = __('Type a reply… (Enter to send, Shift+Enter for a new line)', 'zinn-chat');
			var noteBox = h('label', 'zc-note'); var noteCb = h('input'); noteCb.type = 'checkbox';
			noteBox.appendChild(noteCb); noteBox.appendChild(document.createTextNode(' ' + __('Internal note', 'zinn-chat')));
			var send = btn(__('Send', 'zinn-chat'), 'button-primary');
			form.appendChild(input); form.appendChild(noteBox); form.appendChild(send);
			el.appendChild(head); el.appendChild(info); el.appendChild(msgs); el.appendChild(typing); el.appendChild(form);
			emit('zinn-chat:pane-init', { id: id, pane: el, form: form, input: input, insert: inserter(input) });

			function render(d) {
				p.data = d;
				tabLabel.textContent = (d.name || d.email || ('#' + id)).slice(0, 18);
				who.textContent = '';
				who.appendChild(h('strong', '', d.name || d.email || sprintf(__('Visitor #%d', 'zinn-chat'), id)));
				who.appendChild(h('span', 'zc-status zc-' + d.status, statusName[d.status]));
				info.textContent = '';
				if (d.email) { info.appendChild(h('span', '', d.email)); }
				if (d.page_url) { var a = h('a', '', d.page_url); a.href = d.page_url; a.target = '_blank'; a.rel = 'noopener'; info.appendChild(a); }
				if (d.language) { info.appendChild(h('span', '', d.language)); }
				/* translators: %d: ticket number */
				if (d.ticket_id) { var tk = h('a', '', sprintf(__('Ticket #%d', 'zinn-chat'), d.ticket_id)); tk.href = cfg.adminUrl + '?page=zinn-chat-tickets&ticket=' + d.ticket_id; info.appendChild(tk); }
				Object.keys(d.orders || {}).forEach(function (oid) { info.appendChild(h('span', 'zc-order', d.orders[oid])); });
				actions.textContent = '';
				if (d.status === 'waiting' || (d.status === 'bot')) { actions.appendChild(btn(__('Take this chat', 'zinn-chat'), 'button-primary', function () { post('agent/chat/' + id + '/take').then(apply).catch(fail); })); }
				if (d.status === 'staff' || d.status === 'waiting') { actions.appendChild(btn(__('Back to the assistant', 'zinn-chat'), '', function () { post('agent/chat/' + id + '/bot').then(apply).catch(fail); })); }
				if (!d.ticket_id) { actions.appendChild(btn(__('Make a ticket', 'zinn-chat'), '', function () { var email = d.email || window.prompt(__('The visitor\'s email address', 'zinn-chat')); if (email) { post('agent/chat/' + id + '/ticket', { email: email }).then(apply).catch(fail); } })); }
				if (d.status !== 'closed') { actions.appendChild(btn(__('End chat', 'zinn-chat'), 'zc-danger', function () { post('agent/chat/' + id + '/close').then(apply).catch(fail); })); }
				typing.textContent = d.visitor_typing ? __('The visitor is typing…', 'zinn-chat') : '';
				emit('zinn-chat:pane', { id: id, data: d, info: info, actions: actions, insert: inserter(input), refresh: p.load });
			}

			function bubble(m) {
				if (msgs.querySelector('[data-id="' + m.id + '"]')) { return; }
				var b = h('div', 'zc-msg zc-' + m.role); b.setAttribute('data-id', m.id);
				var meta = h('div', 'zc-msg-meta', (m.role === 'agent' ? (m.name || roleName.agent) : roleName[m.role]) + ' · ' + when(m.at));
				if (m.role !== 'system') { b.appendChild(meta); }
				var t = h('div', 'zc-msg-text'); linkify(t, m.text); b.appendChild(t);
				if (m.sources && m.sources.length) {
					var s = h('div', 'zc-msg-src');
					m.sources.forEach(function (x) { var a = h('a', '', x.title || x.url); a.href = x.url; a.target = '_blank'; a.rel = 'noopener'; s.appendChild(a); });
					b.appendChild(s);
				}
				msgs.appendChild(b);
			}

			function apply(d) {
				if (!d || !d.ok) { return; }
				var stick = msgs.scrollHeight - msgs.scrollTop - msgs.clientHeight < 60;
				(d.messages || []).forEach(function (m) { bubble(m); p.after = Math.max(p.after, m.id); });
				render(d);
				if (stick) { msgs.scrollTop = msgs.scrollHeight; }
			}

			p.load = function (now) {
				clearTimeout(p.timer);
				var go = function () {
					api('agent/chat/' + id + '?after=' + p.after).then(apply).catch(function () {}).then(function () {
						var live = p.data && (p.data.status === 'staff' || p.data.status === 'waiting');
						p.timer = setTimeout(go, document.hidden ? 15000 : (live ? 3000 : 10000));
					});
				};
				if (now) { go(); } else { p.timer = setTimeout(go, 3000); }
			};
			p.stop = function () { clearTimeout(p.timer); };

			var typingSent = 0;
			function doSend() {
				var text = input.value.trim();
				if (!text) { return; }
				send.disabled = true;
				post('agent/chat/' + id + '/reply', { text: text, note: noteCb.checked }).then(function (d) { input.value = ''; noteCb.checked = false; apply(d); }).catch(fail).then(function () { send.disabled = false; input.focus(); });
			}
			send.addEventListener('click', doSend);
			input.addEventListener('keydown', function (e) {
				if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); doSend(); return; }
				if (Date.now() - typingSent > 4000 && !noteCb.checked) { typingSent = Date.now(); post('agent/chat/' + id + '/typing'); }
			});
			return p;
		}

		// Keyboard: Alt+1…9 switch between open chats.
		document.addEventListener('keydown', function (e) {
			if (e.altKey && /^[1-9]$/.test(e.key) && state.open[+e.key - 1]) { e.preventDefault(); activate(state.open[+e.key - 1]); }
		});

		var params = new URLSearchParams(location.search);
		refresh().then(function () { if (params.get('chat')) { openChat(+params.get('chat')); } });
		setInterval(function () { refresh(); }, 4000);
	}

	/* ─────────────────────────── TICKETS ─────────────────────────── */
	function tickets() {
		var state = { status: 'active', search: '', page: 1 };
		var params = new URLSearchParams(location.search);
		app.textContent = '';
		var root = h('div', 'zc-tickets');
		app.appendChild(root);

		function listView() {
			history.replaceState(null, '', cfg.adminUrl + '?page=zinn-chat-tickets');
			root.textContent = '';
			var head = h('div', 'zc-bar');
			head.appendChild(h('h1', 'zc-title', __('Tickets', 'zinn-chat')));
			head.appendChild(btn(__('New ticket', 'zinn-chat'), 'button-primary', newTicket));
			root.appendChild(head);
			var filters = h('div', 'zc-filters');
			var search = h('input', 'zc-search'); search.type = 'search'; search.placeholder = __('Search subject, email or number', 'zinn-chat'); search.value = state.search;
			search.addEventListener('change', function () { state.search = search.value; state.page = 1; load(); });
			root.appendChild(filters);
			var table = h('table', 'widefat striped zc-table');
			var thead = h('thead'); var tr = h('tr');
			['#', __('Subject', 'zinn-chat'), __('Customer', 'zinn-chat'), __('Status', 'zinn-chat'), __('Priority', 'zinn-chat'), __('Updated', 'zinn-chat')].forEach(function (c) { tr.appendChild(h('th', '', c)); });
			thead.appendChild(tr); table.appendChild(thead);
			var tbody = h('tbody'); table.appendChild(tbody);
			root.appendChild(table);
			var pager = h('div', 'zc-pager'); root.appendChild(pager);
			var labels = { active: __('Needs attention', 'zinn-chat'), open: __('Open', 'zinn-chat'), pending: __('Waiting for customer', 'zinn-chat'), solved: __('Solved', 'zinn-chat'), closed: __('Closed', 'zinn-chat'), all: __('All', 'zinn-chat') };
			function load() {
				api('agent/tickets?status=' + state.status + '&search=' + encodeURIComponent(state.search) + '&page=' + state.page).then(function (r) {
					filters.textContent = '';
					Object.keys(labels).forEach(function (k) {
						var n = k === 'active' ? (r.counts.open || 0) + (r.counts.pending || 0) : (k === 'all' ? '' : r.counts[k]);
						var b = btn(labels[k] + (n !== '' ? ' (' + n + ')' : ''), k === state.status ? 'is-active' : '', function () { state.status = k; state.page = 1; load(); });
						filters.appendChild(b);
					});
					filters.appendChild(search);
					tbody.textContent = '';
					if (!r.rows.length) { var e = h('tr'); var td = h('td', '', __('No tickets.', 'zinn-chat')); td.colSpan = 6; e.appendChild(td); tbody.appendChild(e); }
					r.rows.forEach(function (t) {
						var row = h('tr', t.unread ? 'zc-unread' : '');
						row.appendChild(h('td', '', t.id));
						var sub = h('td'); var a = h('a', '', t.subject || '—'); a.href = '#'; a.addEventListener('click', function (ev) { ev.preventDefault(); ticketView(t.id); }); sub.appendChild(a);
						if (t.source_site) { sub.appendChild(h('span', 'zc-muted', ' · ' + t.source_site)); }
						row.appendChild(sub);
						row.appendChild(h('td', '', (t.name ? t.name + ' ' : '') + '<' + t.email + '>'));
						row.appendChild(h('td', '', labels[t.status] || t.status));
						row.appendChild(h('td', '', t.priority));
						row.appendChild(h('td', '', ago(t.updated_at)));
						tbody.appendChild(row);
						emit('zinn-chat:ticket-row', { ticket: t, node: row, subject: sub });
					});
					pager.textContent = '';
					var pages = Math.ceil(r.total / 25);
					if (pages > 1) {
						if (state.page > 1) { pager.appendChild(btn('‹', '', function () { state.page--; load(); })); }
						/* translators: 1: current page, 2: number of pages */
						pager.appendChild(h('span', '', sprintf(__('Page %1$d of %2$d', 'zinn-chat'), state.page, pages)));
						if (state.page < pages) { pager.appendChild(btn('›', '', function () { state.page++; load(); })); }
					}
				}).catch(fail);
			}
			load();
		}

		function newTicket() {
			root.textContent = '';
			root.appendChild(h('h1', 'zc-title', __('New ticket', 'zinn-chat')));
			var f = h('div', 'zc-form');
			var fields = {};
			[['name', __('Customer name', 'zinn-chat'), 'text'], ['email', __('Customer email', 'zinn-chat'), 'email'], ['subject', __('Subject', 'zinn-chat'), 'text']].forEach(function (x) {
				var l = h('label', '', x[1]); var i = h('input', 'regular-text'); i.type = x[2]; fields[x[0]] = i; l.appendChild(i); f.appendChild(l);
			});
			var body = h('textarea', 'large-text'); body.rows = 6; var lb = h('label', '', __('Message', 'zinn-chat')); lb.appendChild(body); f.appendChild(lb);
			f.appendChild(btn(__('Create ticket', 'zinn-chat'), 'button-primary', function () {
				post('agent/tickets', { name: fields.name.value, email: fields.email.value, subject: fields.subject.value, body: body.value }).then(function (r) { ticketView(r.id); }).catch(fail);
			}));
			f.appendChild(btn(__('Cancel', 'zinn-chat'), '', listView));
			root.appendChild(f);
		}

		function ticketView(id) {
			history.replaceState(null, '', cfg.adminUrl + '?page=zinn-chat-tickets&ticket=' + id);
			root.textContent = '';
			var head = h('div', 'zc-bar');
			head.appendChild(btn('← ' + __('All tickets', 'zinn-chat'), '', listView));
			var title = h('h1', 'zc-title'); head.appendChild(title);
			root.appendChild(head);
			var grid = h('div', 'zc-ticket');
			var main = h('div', 'zc-ticket-main'); var side = h('aside', 'zc-ticket-side');
			grid.appendChild(main); grid.appendChild(side); root.appendChild(grid);
			var thread = h('div', 'zc-thread'); main.appendChild(thread);
			var reply = h('div', 'zc-reply');
			var input = h('textarea'); input.rows = 5; input.placeholder = __('Write a reply…', 'zinn-chat');
			var noteBox = h('label', 'zc-note'); var noteCb = h('input'); noteCb.type = 'checkbox'; noteBox.appendChild(noteCb); noteBox.appendChild(document.createTextNode(' ' + __('Internal note (the customer does not see it)', 'zinn-chat')));
			var after = h('select');
			[['pending', __('then: waiting for customer', 'zinn-chat')], ['solved', __('then: solved', 'zinn-chat')], ['open', __('then: keep open', 'zinn-chat')]].forEach(function (o) { var op = h('option', '', o[1]); op.value = o[0]; after.appendChild(op); });
			var send = btn(__('Send reply', 'zinn-chat'), 'button-primary');
			reply.appendChild(input); reply.appendChild(noteBox); reply.appendChild(after); reply.appendChild(send);
			main.appendChild(reply);
			emit('zinn-chat:ticket-init', { id: id, form: reply, input: input, insert: inserter(input) });

			function draw(r) {
				var t = r.ticket;
				/* translators: 1: ticket number, 2: ticket subject */
				title.textContent = sprintf(__('#%1$d %2$s', 'zinn-chat'), t.id, t.subject);
				thread.textContent = '';
				r.replies.forEach(function (x) {
					var b = h('div', 'zc-msg zc-' + (x.author === 'customer' ? 'visitor' : x.author));
					b.appendChild(h('div', 'zc-msg-meta', (x.name || x.author) + ' · ' + when(x.at) + (x.via === 'email' ? ' · ' + __('by email', 'zinn-chat') : '') + (x.author === 'note' ? ' · ' + __('internal note', 'zinn-chat') : '')));
					var tx = h('div', 'zc-msg-text'); linkify(tx, x.text); b.appendChild(tx);
					thread.appendChild(b);
				});
				side.textContent = '';
				side.appendChild(h('h2', '', __('Details', 'zinn-chat')));
				var dl = h('dl');
				function row(k, v) { dl.appendChild(h('dt', '', k)); var dd = h('dd'); if (v instanceof Node) { dd.appendChild(v); } else { dd.textContent = v; } dl.appendChild(dd); }
				row(__('Customer', 'zinn-chat'), (t.name ? t.name + ' ' : '') + '<' + t.email + '>');
				row(__('Opened', 'zinn-chat'), when(t.created_at));
				row(__('Channel', 'zinn-chat'), t.channel + (t.source_site ? ' · ' + t.source_site : ''));
				var status = h('select');
				[['open', __('Open', 'zinn-chat')], ['pending', __('Waiting for customer', 'zinn-chat')], ['solved', __('Solved', 'zinn-chat')], ['closed', __('Closed', 'zinn-chat')]].forEach(function (o) { var op = h('option', '', o[1]); op.value = o[0]; op.selected = o[0] === t.status; status.appendChild(op); });
				status.addEventListener('change', function () { post('agent/tickets/' + t.id + '/update', { status: status.value }).then(draw).catch(fail); });
				row(__('Status', 'zinn-chat'), status);
				var prio = h('select');
				[['low', __('Low', 'zinn-chat')], ['normal', __('Normal', 'zinn-chat')], ['high', __('High', 'zinn-chat')], ['urgent', __('Urgent', 'zinn-chat')]].forEach(function (o) { var op = h('option', '', o[1]); op.value = o[0]; op.selected = o[0] === t.priority; prio.appendChild(op); });
				prio.addEventListener('change', function () { post('agent/tickets/' + t.id + '/update', { priority: prio.value }).then(draw).catch(fail); });
				row(__('Priority', 'zinn-chat'), prio);
				var who = h('select'); var none = h('option', '', __('Nobody', 'zinn-chat')); none.value = 0; who.appendChild(none);
				(r.agents || []).forEach(function (a) { var op = h('option', '', a.name); op.value = a.id; op.selected = a.id === t.assignee_id; who.appendChild(op); });
				who.addEventListener('change', function () { post('agent/tickets/' + t.id + '/update', { assignee_id: +who.value }).then(draw).catch(fail); });
				row(__('Assigned to', 'zinn-chat'), who);
				/* translators: 1: order number, 2: order date, 3: order status */
				if (r.order && r.order.id) { var oa = h('a', '', sprintf(__('Order #%1$s · %2$s · %3$s', 'zinn-chat'), r.order.number, r.order.status, r.order.total)); oa.href = r.order.url; row(__('Order', 'zinn-chat'), oa); }
				side.appendChild(dl);
				if (cfg.manage) {
					side.appendChild(btn(__('Delete ticket', 'zinn-chat'), 'zc-danger', function () { if (window.confirm(__('Delete this ticket and all its messages?', 'zinn-chat'))) { api('agent/tickets/' + t.id, { method: 'DELETE' }).then(listView).catch(fail); } }));
				}
				emit('zinn-chat:ticket', { id: t.id, data: r, side: side, thread: thread, insert: inserter(input), redraw: draw });
			}
			send.addEventListener('click', function () {
				if (!input.value.trim()) { return; }
				send.disabled = true;
				post('agent/tickets/' + id + '/reply', { text: input.value, note: noteCb.checked, status: after.value }).then(function (r) { input.value = ''; noteCb.checked = false; draw(r); }).catch(fail).then(function () { send.disabled = false; });
			});
			api('agent/tickets/' + id).then(draw).catch(fail);
		}

		if (params.get('ticket')) { ticketView(+params.get('ticket')); } else { listView(); }
	}

	/* ─────────────────────────── ASSISTANT ─────────────────────────── */
	function assistant() {
		app.textContent = '';
		var root = h('div', 'zc-assistant');
		root.appendChild(h('h1', 'zc-title', __('Test the assistant', 'zinn-chat')));
		root.appendChild(h('p', 'description', __('Ask what your visitors ask. You see the answer and every page it found, with how well each matched, when it was last updated, and warnings for pages that look out of date. Fix a page, press Re-read, and ask again.', 'zinn-chat')));
		var ask = h('div', 'zc-ask');
		var q = h('textarea', 'large-text'); q.rows = 2; q.placeholder = __('For example: What is your returns policy?', 'zinn-chat');
		var go = btn(__('Ask', 'zinn-chat'), 'button-primary');
		ask.appendChild(q); ask.appendChild(go); root.appendChild(ask);
		var result = h('div', 'zc-result'); root.appendChild(result);
		var idx = h('section', 'zc-index'); root.appendChild(idx);
		app.appendChild(root);

		function flagText(s) {
			var out = [];
			if (s.flags.indexOf('old') !== -1) { out.push(__('Not updated for over a year', 'zinn-chat')); }
			/* translators: %s: title of the newer page */
			if (s.flags.indexOf('outdated') !== -1) { out.push(sprintf(__('A newer page covers the same subject: %s', 'zinn-chat'), s.newer_title || s.newer_url)); }
			return out;
		}

		function reread(itemId, button) {
			button.disabled = true;
			post('agent/index/reindex', { item_id: itemId }).then(function () { button.textContent = __('Re-read ✓', 'zinn-chat'); loadIndex(); }).catch(fail);
		}

		go.addEventListener('click', function () {
			var question = q.value.trim(); if (!question) { return; }
			go.disabled = true; result.textContent = __('Asking…', 'zinn-chat');
			post('agent/assistant/ask', { question: question }).then(function (r) {
				result.textContent = '';
				var box = h('div', 'zc-answer zc-' + r.status);
				/* translators: 1: answer outcome, 2: search method, 3: tokens sent, 4: tokens received */
				box.appendChild(h('div', 'zc-answer-meta', sprintf(__('Outcome: %1$s · Search: %2$s · Tokens: %3$d in, %4$d out', 'zinn-chat'), r.status, r.mode === 'semantic' ? __('by meaning and words', 'zinn-chat') : (r.mode === 'keyword' ? __('by words only (no embedding key)', 'zinn-chat') : __('nothing indexed', 'zinn-chat')), r.tokens[0], r.tokens[1])));
				if (r.failure) {
					var fl = h('div', 'notice notice-error inline'); var fp = h('p', '', r.failure.message || '');
					if (r.failure.link) { var fa = h('a', '', ' ' + __('Fix it', 'zinn-chat')); fa.href = r.failure.link; fa.target = '_blank'; fp.appendChild(fa); }
					fl.appendChild(fp); box.appendChild(fl);
					if (!r.answer) { box.appendChild(h('p', '', __('The AI is not set up yet or could not answer.', 'zinn-chat'))); var ai = h('a', 'button', __('AI providers', 'zinn-chat')); ai.href = cfg.aiUrl; box.appendChild(ai); }
				}
				var text = h('div', 'zc-answer-text'); linkify(text, r.answer); box.appendChild(text);
				if (r.error) { box.appendChild(h('p', 'zc-muted', r.error)); }
				result.appendChild(box);
				/* translators: %d: number of sources */
				result.appendChild(h('h2', '', sprintf(__('Sources found (%d)', 'zinn-chat'), r.sources.length)));
				if (!r.sources.length) { result.appendChild(h('p', '', __('Nothing on the site matched. Add a page that answers this, or check the Site index settings.', 'zinn-chat'))); }
				var table = h('table', 'widefat striped zc-sources');
				var hr = h('tr');
				[__('Score', 'zinn-chat'), __('Page', 'zinn-chat'), __('Last updated', 'zinn-chat'), __('Passage', 'zinn-chat'), ''].forEach(function (c) { hr.appendChild(h('th', '', c)); });
				var th = h('thead'); th.appendChild(hr); table.appendChild(th);
				var tb = h('tbody'); table.appendChild(tb);
				r.sources.forEach(function (s) {
					var row = h('tr', s.used ? 'zc-used' : '');
					row.appendChild(h('td', 'zc-score', s.score.toFixed(3) + (s.used ? ' ✓' : '')));
					var page = h('td'); var a = h('a', '', s.title || s.url); a.href = s.url; a.target = '_blank'; a.rel = 'noopener'; page.appendChild(a);
					page.appendChild(h('div', 'zc-muted', s.url));
					flagText(s).forEach(function (f) { page.appendChild(h('div', 'zc-flag', '⚠ ' + f)); });
					row.appendChild(page);
					row.appendChild(h('td', '', s.modified ? new Date(s.modified).toLocaleDateString() : '—'));
					row.appendChild(h('td', 'zc-snippet', s.snippet));
					var acts = h('td', 'zc-acts');
					if (s.edit_url) { var e = h('a', 'button button-small', __('Edit', 'zinn-chat')); e.href = s.edit_url; e.target = '_blank'; acts.appendChild(e); }
					if (cfg.manage) { var rr = btn(__('Re-read', 'zinn-chat'), 'button-small'); rr.addEventListener('click', function () { reread(s.item_id, rr); }); acts.appendChild(rr); }
					row.appendChild(acts);
					tb.appendChild(row);
				});
				result.appendChild(table);
			}).catch(fail).then(function () { go.disabled = false; });
		});
		q.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); go.click(); } });

		var iState = { search: '', status: '', page: 1 };
		function loadIndex() {
			api('agent/index?search=' + encodeURIComponent(iState.search) + '&status=' + iState.status + '&page=' + iState.page).then(function (r) {
				idx.textContent = '';
				idx.appendChild(h('h2', '', __('What the assistant has read', 'zinn-chat')));
				var st = r.stats;
				/* translators: 1: pages read, 2: pages waiting, 3: pages with errors, 4: passages, 5: pages understood by meaning */
				var p = h('p', 'zc-stats', sprintf(__('%1$d pages read · %2$d waiting · %3$d with errors · %4$d passages · %5$d understood by meaning', 'zinn-chat'), st.counts.indexed || 0, r.remaining, st.counts.error || 0, st.passages, st.semantic));
				idx.appendChild(p);
				/* translators: %s: the AI model that builds the search index, e.g. gemini/text-embedding-004/256 */
				idx.appendChild(h('p', 'zc-muted', st.model ? sprintf(__('Embedding model: %s', 'zinn-chat'), st.model) : __('No embedding model: search is by words only. Connect Google Gemini, OpenAI, Mistral or OpenRouter to search by meaning.', 'zinn-chat')));
				if (st.blocked) { idx.appendChild(h('div', 'notice notice-warning inline', __('Building meanings is paused:', 'zinn-chat') + ' ' + st.blocked)); }
				if (cfg.manage) {
					var tools = h('p');
					tools.appendChild(btn(__('Re-read the whole site', 'zinn-chat'), '', function () { post('agent/index/reindex', { all: true }).then(function (x) { window.alert(x.message); loadIndex(); }).catch(fail); }));
					tools.appendChild(btn(__('Rebuild all meanings', 'zinn-chat'), '', function () { post('agent/index/reembed', { all: true }).then(function (x) { window.alert(x.message); loadIndex(); }).catch(fail); }));
					idx.appendChild(tools);
				}
				var f = h('div', 'zc-filters');
				var search = h('input', 'zc-search'); search.type = 'search'; search.placeholder = __('Filter by title or address', 'zinn-chat'); search.value = iState.search;
				search.addEventListener('change', function () { iState.search = search.value; iState.page = 1; loadIndex(); });
				var status = h('select');
				[['', __('All', 'zinn-chat')], ['indexed', __('Read', 'zinn-chat')], ['pending', __('Waiting', 'zinn-chat')], ['error', __('Errors', 'zinn-chat')]].forEach(function (o) { var op = h('option', '', o[1]); op.value = o[0]; op.selected = o[0] === iState.status; status.appendChild(op); });
				status.addEventListener('change', function () { iState.status = status.value; iState.page = 1; loadIndex(); });
				f.appendChild(search); f.appendChild(status); idx.appendChild(f);
				var table = h('table', 'widefat striped');
				var hr = h('tr');
				[__('Page', 'zinn-chat'), __('Type', 'zinn-chat'), __('Status', 'zinn-chat'), __('Passages', 'zinn-chat'), __('Last updated', 'zinn-chat'), __('Read', 'zinn-chat'), ''].forEach(function (c) { hr.appendChild(h('th', '', c)); });
				var th = h('thead'); th.appendChild(hr); table.appendChild(th);
				var tb = h('tbody');
				r.rows.forEach(function (it) {
					var row = h('tr');
					var page = h('td'); var a = h('a', '', it.title || it.url || ('#' + it.object_id)); a.href = it.url; a.target = '_blank'; a.rel = 'noopener'; page.appendChild(a);
					if (it.error) { page.appendChild(h('div', 'zc-flag', it.error)); }
					row.appendChild(page);
					row.appendChild(h('td', '', it.kind || it.object_type));
					row.appendChild(h('td', '', it.status + (it.semantic ? ' · ' + __('by meaning', 'zinn-chat') : '')));
					row.appendChild(h('td', '', it.chunks));
					row.appendChild(h('td', '', it.modified ? new Date(it.modified).toLocaleDateString() : '—'));
					row.appendChild(h('td', '', ago(it.indexed_at)));
					var acts = h('td', 'zc-acts');
					if (it.edit_url) { var e = h('a', 'button button-small', __('Edit', 'zinn-chat')); e.href = it.edit_url; e.target = '_blank'; acts.appendChild(e); }
					if (cfg.manage) {
						var rr = btn(__('Re-read', 'zinn-chat'), 'button-small'); rr.addEventListener('click', function () { reread(it.id, rr); }); acts.appendChild(rr);
						var re = btn(__('Rebuild meaning', 'zinn-chat'), 'button-small'); re.addEventListener('click', function () { re.disabled = true; post('agent/index/reembed', { item_id: it.id }).then(function (x) { re.textContent = x.ok ? __('Done ✓', 'zinn-chat') : __('Failed', 'zinn-chat'); }).catch(fail); }); acts.appendChild(re);
					}
					row.appendChild(acts);
					tb.appendChild(row);
				});
				table.appendChild(tb); idx.appendChild(table);
				var pages = Math.ceil(r.total / 50);
				if (pages > 1) {
					var pg = h('div', 'zc-pager');
					if (iState.page > 1) { pg.appendChild(btn('‹', '', function () { iState.page--; loadIndex(); })); }
					pg.appendChild(h('span', '', sprintf(__('Page %1$d of %2$d', 'zinn-chat'), iState.page, pages)));
					if (iState.page < pages) { pg.appendChild(btn('›', '', function () { iState.page++; loadIndex(); })); }
					idx.appendChild(pg);
				}
			}).catch(fail);
		}
		loadIndex();
	}

	var views = { inbox: inbox, tickets: tickets, assistant: assistant };
	/**
	 * Pro can add views (a knowledge base, reports…): window.zinnChatViews = { name: fn(app, cfg) }.
	 */
	var extra = window.zinnChatViews || {};
	var view = app.getAttribute('data-view');
	(extra[view] || views[view] || function () {})(app, cfg);
}(window.wp, window.zinnChatConsole || {}));
