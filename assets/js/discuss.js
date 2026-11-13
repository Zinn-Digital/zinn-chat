/**
 * Zinn® Chat 2.14.0: team discussions and the notification centre.
 *
 * A bell in the inbox bar lists the agent's mentions, replies, reactions and assignments; each
 * chat gets a Discuss panel (about this chat, and about this contact); Pro mounts the same panel
 * on a knowledge base article (window.zinnChatDiscuss.mount). Visitors never see any of it.
 * Every text is built with textContent: nothing an agent types is parsed as HTML.
 *
 * @package ZinnDigital\ZinnChat
 */
(function (wp, d) {
	'use strict';
	if (!wp || !wp.apiFetch || !wp.i18n) { return; }
	var __ = wp.i18n.__, sprintf = wp.i18n.sprintf, _n = wp.i18n._n;
	var api = function (path, opts) { return wp.apiFetch(Object.assign({ path: '/zinn-chat/v1/' + path }, opts || {})); };
	var post = function (path, data) { return api(path, { method: 'POST', data: data || {} }); };
	function h(tag, cls, text) { var n = d.createElement(tag); if (cls) { n.className = cls; } if (text !== undefined && text !== null) { n.textContent = String(text); } return n; }
	function btn(label, cls, fn) { var b = h('button', 'button ' + (cls || ''), label); b.type = 'button'; if (fn) { b.addEventListener('click', fn); } return b; }
	function when(iso) { return iso ? new Date(iso).toLocaleString() : ''; }
	function say(e) { window.alert((e && e.message) || __('Something went wrong. Please try again.', 'zinn-chat')); }
	function cfg() { return window.zinnChatConsole || {}; }
	var me = function () { return +(cfg().me || 0); };

	var kindText = {
		/* translators: %s: the agent who mentioned you */
		mention: __('%s mentioned you', 'zinn-chat'),
		/* translators: %s: the agent who replied */
		reply: __('%s replied in a discussion', 'zinn-chat'),
		/* translators: %s: the agent who reacted */
		reaction: __('%s reacted to your message', 'zinn-chat'),
		/* translators: %s: the agent who assigned the ticket */
		assignment: __('%s assigned a ticket to you', 'zinn-chat')
	};
	var whereText = {
		chat: __('Chat', 'zinn-chat'),
		contact: __('Contact', 'zinn-chat'),
		kb: __('Article', 'zinn-chat'),
		ticket: __('Ticket', 'zinn-chat')
	};

	/** Where a notification leads. */
	function open(n) {
		var admin = cfg().adminUrl || 'admin.php';
		if (n.object_type === 'chat') { d.dispatchEvent(new CustomEvent('zinn-chat:open', { detail: { id: +n.object_key } })); return; }
		if (n.object_type === 'ticket') { window.location.href = admin + '?page=zinn-chat-tickets&ticket=' + encodeURIComponent(n.object_key); return; }
		if (n.object_type === 'kb') { window.location.href = admin.replace(/admin\.php$/, 'post.php') + '?post=' + encodeURIComponent(n.object_key) + '&action=edit'; }
	}

	/** The bell: unread count, and the list on click. */
	function bell(bar) {
		var wrap = h('div', 'zc-bell');
		var b = btn('🔔', 'zc-bell-btn');
		b.setAttribute('aria-haspopup', 'true'); b.setAttribute('aria-expanded', 'false');
		var count = h('span', 'zc-bell-count'); count.hidden = true;
		b.appendChild(count);
		var panel = h('div', 'zc-bell-panel'); panel.hidden = true; panel.setAttribute('role', 'region');
		panel.setAttribute('aria-label', __('Notifications', 'zinn-chat'));
		wrap.appendChild(b); wrap.appendChild(panel);
		bar.appendChild(wrap);
		var rows = [];
		function label(unread) {
			count.textContent = unread > 99 ? '99+' : String(unread);
			count.hidden = !unread;
			/* translators: %d: how many unread notifications */
			b.setAttribute('aria-label', sprintf(_n('Notifications, %d unread', 'Notifications, %d unread', unread, 'zinn-chat'), unread));
		}
		function draw() {
			panel.textContent = '';
			var head = h('div', 'zc-bell-head');
			head.appendChild(h('strong', '', __('Notifications', 'zinn-chat')));
			head.appendChild(btn(__('Mark all read', 'zinn-chat'), 'button-link', function () { post('agent/notifications/read', { ids: [] }).then(apply).catch(say); }));
			panel.appendChild(head);
			if (!rows.length) { panel.appendChild(h('p', 'zc-empty', __('Mentions, replies, reactions and assignments show here.', 'zinn-chat'))); return; }
			var list = h('ul', 'zc-bell-list');
			rows.forEach(function (n) {
				var li = h('li', 'zc-bell-row' + (n.read ? '' : ' is-unread'));
				var go = btn('', 'button-link zc-bell-go', function () {
					post('agent/notifications/read', { ids: [n.id] }).then(apply).catch(function () {});
					panel.hidden = true; b.setAttribute('aria-expanded', 'false');
					open(n);
				});
				go.appendChild(h('strong', '', sprintf(kindText[n.kind] || '%s', n.actor || __('Someone', 'zinn-chat'))));
				go.appendChild(h('span', 'zc-bell-where', (whereText[n.object_type] || '') + (n.object_type === 'contact' ? ' ' + n.object_key : ' #' + n.object_key) + ' · ' + when(n.at)));
				if (n.text) { go.appendChild(h('span', 'zc-bell-text', n.text)); }
				li.appendChild(go);
				list.appendChild(li);
			});
			panel.appendChild(list);
		}
		function apply(r) { rows = r.rows || []; label(+r.unread || 0); if (!panel.hidden) { draw(); } }
		function load() { return api('agent/notifications').then(apply).catch(function () {}); }
		b.addEventListener('click', function () { panel.hidden = !panel.hidden; b.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true'); if (!panel.hidden) { draw(); load(); } });
		d.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) { panel.hidden = true; b.setAttribute('aria-expanded', 'false'); b.focus(); } });
		load();
		setInterval(function () { if (!d.hidden) { load(); } }, 30000);
	}

	var agents = null;
	function mentionable() {
		if (!agents) { agents = api('agent/mentionable').then(function (r) { return r.agents || []; }).catch(function () { agents = null; return []; }); }
		return agents;
	}

	/**
	 * A discussion thread in `el` about (type, key).
	 *
	 * @param {HTMLElement} el   Container.
	 * @param {string}      type chat | contact | kb.
	 * @param {string}      key  Chat id, e-mail address or article id.
	 */
	function mount(el, type, key) {
		el.textContent = '';
		el.classList.add('zc-discuss');
		var list = h('div', 'zc-discuss-list'); list.setAttribute('aria-live', 'polite');
		var form = h('div', 'zc-discuss-form');
		var input = h('textarea'); input.rows = 2;
		input.placeholder = __('Discuss with your team… type @ and a name to mention someone', 'zinn-chat');
		input.setAttribute('aria-label', __('Message to your team', 'zinn-chat'));
		var hint = h('div', 'zc-discuss-hint');
		var send = btn(__('Post', 'zinn-chat'), 'button-primary');
		var state = { parent: 0, reactions: {}, busy: false };
		var replying = h('div', 'zc-discuss-replying'); replying.hidden = true;
		form.appendChild(replying); form.appendChild(input); form.appendChild(hint); form.appendChild(send);
		el.appendChild(list); el.appendChild(form);

		function draw(r) {
			state.reactions = r.reactions || state.reactions;
			list.textContent = '';
			var msgs = r.messages || [];
			if (!msgs.length) { list.appendChild(h('p', 'zc-empty', __('No discussion yet. Only your team sees what is written here.', 'zinn-chat'))); }
			var byId = {};
			msgs.forEach(function (m) { byId[m.id] = m; });
			msgs.forEach(function (m) {
				var item = h('div', 'zc-discuss-msg' + (m.parent_id ? ' is-reply' : ''));
				item.appendChild(h('div', 'zc-msg-meta', (m.author || __('Someone', 'zinn-chat')) + ' · ' + when(m.at)));
				if (m.parent_id && byId[m.parent_id]) {
					/* translators: %s: the author of the message being answered */
					item.appendChild(h('div', 'zc-discuss-quote', sprintf(__('In reply to %s', 'zinn-chat'), byId[m.parent_id].author || '')));
				}
				item.appendChild(h('div', 'zc-discuss-text', m.text));
				var acts = h('div', 'zc-discuss-acts');
				Object.keys(state.reactions).forEach(function (k) {
					var who = (m.reactions && m.reactions[k]) || [];
					var rb = btn(state.reactions[k] + (who.length ? ' ' + who.length : ''), 'button-small' + (who.indexOf(me()) !== -1 ? ' is-active' : ''), function () {
						post('agent/discuss/' + m.id + '/react', { reaction: k }).then(function (x) { m.reactions = x.reactions || {}; draw({ messages: msgs }); }).catch(say);
					});
					rb.setAttribute('aria-pressed', who.indexOf(me()) !== -1 ? 'true' : 'false');
					acts.appendChild(rb);
				});
				acts.appendChild(btn(__('Reply', 'zinn-chat'), 'button-link', function () {
					state.parent = m.id;
					replying.hidden = false; replying.textContent = '';
					/* translators: %s: the author of the message being answered */
					replying.appendChild(h('span', '', sprintf(__('Replying to %s', 'zinn-chat'), m.author || '')));
					replying.appendChild(btn(__('Cancel', 'zinn-chat'), 'button-link', function () { state.parent = 0; replying.hidden = true; }));
					input.focus();
				}));
				item.appendChild(acts);
				list.appendChild(item);
			});
		}
		function load() {
			return api('agent/discuss?type=' + encodeURIComponent(type) + '&key=' + encodeURIComponent(key)).then(draw).catch(function (e) {
				list.textContent = '';
				list.appendChild(h('p', 'zc-flag', (e && e.message) || __('Something went wrong. Please try again.', 'zinn-chat')));
				form.hidden = e && e.code === 'pro_required';
			});
		}
		function submit() {
			var text = input.value.trim();
			if (!text || state.busy) { return; }
			state.busy = true; send.disabled = true;
			post('agent/discuss', { type: type, key: key, body: text, parent_id: state.parent }).then(function (r) {
				input.value = ''; state.parent = 0; replying.hidden = true; hint.textContent = ''; draw(r);
			}).catch(say).then(function () { state.busy = false; send.disabled = false; });
		}
		send.addEventListener('click', submit);
		input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); submit(); } });
		// The @ picker: names of agents matching what follows the last @.
		input.addEventListener('input', function () {
			var m = /(^|\s)@([\w.-]*)$/.exec(input.value.slice(0, input.selectionStart));
			hint.textContent = '';
			if (!m) { return; }
			var q = m[2].toLowerCase();
			mentionable().then(function (all) {
				all.filter(function (a) { return a.id !== me() && (a.login.toLowerCase().indexOf(q) === 0 || a.name.toLowerCase().indexOf(q) === 0); }).slice(0, 6).forEach(function (a) {
					hint.appendChild(btn('@' + a.login + ' (' + a.name + ')', 'button-small', function () {
						var at = input.selectionStart;
						var before = input.value.slice(0, at).replace(/@[\w.-]*$/, '@' + a.login + ' ');
						input.value = before + input.value.slice(at);
						hint.textContent = ''; input.focus();
					}));
				});
			});
		});
		load();
		return { reload: load };
	}

	/** A Discuss toggle on each chat pane: about this chat, and about this contact. */
	var panes = {};
	d.addEventListener('zinn-chat:pane-init', function (e) {
		var id = e.detail.id;
		var box = h('section', 'zc-discuss-box'); box.hidden = true;
		var tabs = h('div', 'zc-discuss-tabs');
		var body = h('div');
		box.appendChild(tabs); box.appendChild(body);
		e.detail.pane.insertBefore(box, e.detail.form);
		panes[id] = { box: box, tabs: tabs, body: body, email: '', toggle: null, which: 'chat' };
		function show(which) {
			var p = panes[id]; p.which = which;
			Array.prototype.forEach.call(tabs.children, function (t) { t.classList.toggle('is-active', t.getAttribute('data-which') === which); });
			mount(body, which, which === 'chat' ? String(id) : p.email);
		}
		var t1 = btn(__('About this chat', 'zinn-chat'), 'button-small', function () { show('chat'); }); t1.setAttribute('data-which', 'chat');
		var t2 = btn(__('About this contact', 'zinn-chat'), 'button-small', function () { show('contact'); }); t2.setAttribute('data-which', 'contact'); t2.hidden = true;
		tabs.appendChild(t1); tabs.appendChild(t2);
		panes[id].t2 = t2;
		panes[id].show = show;
	});
	d.addEventListener('zinn-chat:pane', function (e) {
		var p = panes[e.detail.id];
		if (!p) { return; }
		p.email = e.detail.data.email || '';
		p.t2.hidden = !p.email;
		if (!p.toggle) {
			p.toggle = btn(__('Discuss', 'zinn-chat'), '', function () {
				p.box.hidden = !p.box.hidden;
				p.toggle.setAttribute('aria-expanded', p.box.hidden ? 'false' : 'true');
				if (!p.box.hidden) { p.show(p.which); }
			});
			p.toggle.setAttribute('aria-expanded', 'false');
		}
		e.detail.actions.appendChild(p.toggle);
	});
	d.addEventListener('zinn-chat:pane-close', function (e) { delete panes[e.detail.id]; });
	d.addEventListener('zinn-chat:bar', function (e) { bell(e.detail.bar); });

	window.zinnChatDiscuss = { mount: mount };
	// Pro's article screen: a container with data-zc-discuss="kb" data-key="<post id>".
	function auto() { Array.prototype.forEach.call(d.querySelectorAll('[data-zc-discuss]'), function (el) { mount(el, el.getAttribute('data-zc-discuss'), el.getAttribute('data-key')); }); }
	if (d.readyState === 'loading') { d.addEventListener('DOMContentLoaded', auto); } else { auto(); }
}(window.wp, document));
