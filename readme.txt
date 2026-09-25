=== Zinn® Chat ===
Contributors: zinndigital
Plugin URI: https://zinndigital.com/wordpress-plugins/zinn-chat
Author: Neil Lock — CEO, Zinn Digital® Ltd
Author URI: https://zinndigital.com
Tags: live chat, chat, support, helpdesk, ai
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.3.6
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Live chat that answers visitors straight away, hands them to you when it matters, and weighs under 10 KB on the page.

== Description ==

Most live chat widgets cost your visitors several hundred kilobytes of JavaScript on every page, whether or not anybody ever opens them. This one is under 10 KB compressed, loads after your page has finished rendering, and makes **no network requests at all** until a visitor actually clicks it.

When somebody does open it, an assistant answers immediately from what it knows about your site. If it cannot answer — or the visitor asks for a person, or the question is about an order, money or a complaint — it hands the conversation straight to you. Anything that comes in when nobody is there is emailed to you with the transcript.

= What makes it fast =

* Under 10 KB compressed. No framework, no polyfills, no web fonts, no tracking pixel.
* Loaded with `defer`, in the footer, after your page has painted. It cannot delay your content and it cannot shift your layout.
* Its settings travel with the page, so the widget does not have to fetch them.
* Zero requests until a visitor opens the chat. A page nobody chats on costs one cached script and nothing else.
* Its styles live in a shadow root, so your theme cannot break the widget and the widget cannot leak into your theme.

= What it does =

* **AI first responder.** Answers from your own site's content, with links to the page it answered from.
* **Straight to a human when it matters.** Money, orders, complaints and "can I talk to someone" go to a person without the assistant trying its luck first.
* **You answer from one inbox.** Every site you run, in one place, in your Zinn Digital® dashboard.
* **Nothing is lost.** A visitor's message is saved the moment they send it, whether or not anyone is online. Missed conversations are emailed to you with the transcript.
* **Your own name on it.** The premium tier removes the Zinn Digital® badge from the widget, so the chat on your site is yours.
* **Canned replies, transfer between people and business hours** on the premium tier.

= Privacy =

The plugin adds nothing to your site until you paste a key and tick the box — no script, no cookie, no storage, no requests.

Once it is on, the widget stores one thing in the visitor's own browser: the identifier of their conversation, so a page refresh does not lose what they typed. It does not set cookies, does not track people across sites, and does not record IP addresses. The country shown to you is worked out at our edge from the connection and the address itself is never stored.

== Installation ==

1. Upload the plugin and activate it. (If Zinn Digital® hosts your site, it is already there.)
2. In your Zinn Digital® dashboard, open **Live chat**, create a chat for this site and copy its key.
3. In WordPress, go to **Settings → Zinn® Chat**, paste the key, tick **Show the chat**, and save.

Everything else — greeting, colour, business hours, canned replies, who answers — is set once in the dashboard and applies to every site you run.

== External services ==

This plugin connects your site to Zinn Digital®'s chat service, which is what makes the chat work. It is useless without it and it talks to nothing else.

**What is sent, and when**

* **Nothing at all until you switch the chat on.** With the box unticked, the plugin adds no script to your pages and makes no request.
* **Nothing until a visitor opens the chat.** With it on, your pages carry one `<script>` tag pointing at `https://zinndigital.com/embed/zinn-chat.js`, plus your public chat key and its appearance settings. Loading that file is the only network activity; it makes no request of its own until somebody clicks the launcher.
* **When a visitor sends a message** their message, the address of the page they are on, the page's title, the referring address and their browser's language are sent to `https://api.zinndigital.com/v1/public/chat/…`, along with any name or email address they choose to give. That is what a live chat is: their words go to you, through us.
* **While a conversation is open** the widget asks `https://api.zinndigital.com` for new replies. How often is decided by the service, not by the widget, and it slows right down when the conversation goes quiet or the browser tab is hidden.

The public chat key is not a secret — it names your chat and nothing else, and it only works on the web addresses you have listed in your dashboard.

* **If you have a Zinn® Chat Pro licence**, once a day — and when you press Save on the licence box — the plugin sends your licence key, this site's address and the plugin's version number to `https://api.zinndigital.com/v1/plugin-licences/` to ask whether the licence is still valid and whether a newer Pro build exists. Nothing about your visitors or your content is sent. The free plugin never makes this request: the code that makes it is only in the Pro build.

Service: Zinn Digital® — [zinndigital.com](https://zinndigital.com) · [Terms](https://zinndigital.com/legal/terms) · [Privacy](https://zinndigital.com/legal/privacy)

== Frequently Asked Questions ==

= Does it work on a site you do not host? =

Yes. The plugin works on any WordPress site, and any non-WordPress site can use the plain `<script>` snippet instead.

= What happens if nobody is online? =

The visitor still gets an answer from the assistant, and the conversation is emailed to the address you set, with the transcript. Nothing is lost.

= Can I stop it showing on some pages? =

Yes — return `false` from the `zinn_chat_should_render` filter. Checkout pages and signed-in staff are the usual reasons. Zinn® Chat Pro gives you the same thing as a settings screen: show it only on the pages you choose, or everywhere except them, and hide it from people who are signed in.

= What is Zinn® Chat Pro? =

The same plugin with a licence key in it. Pro removes the limits the free version has — one person answering becomes as many as you like, thirty days of history becomes for ever, our name comes off the chat window, and you get saved replies, transfers between colleagues, business hours and 5,000 AI answers a month instead of 100. One licence covers three of your sites. If you host a site with Zinn Digital®, Pro is included free and your key is already waiting on the Licences page of your dashboard.

= Will it slow my site down? =

It is built so that it cannot. It loads after your page has rendered, it is outside the document flow so it cannot shift your layout, and its size is checked automatically on every change we make to it.

= Is there a free tier? =

Yes, and it is a real one rather than a trial. The free tier gives you one operator, thirty days of transcript history and one hundred AI replies a month, for nothing, for ever. The premium tier adds unlimited operators, transcripts kept for ever, five thousand AI replies a month, removal of the Zinn Digital® badge from the widget, canned replies, transfer between people and business hours.

= Do I have to pay for premium? =

Not if you host with Zinn Digital®. Premium is included with your hosting for as long as it is active, on every site in your account, with nothing to buy and no code to enter. If your hosting is cancelled or suspended, the chat returns to the free tier and transcripts go back to thirty days.

== Screenshots ==

1. Settings → Zinn® Chat. Paste the key from your dashboard, tick the box, and that is the whole setup. Until you do, the plugin adds nothing to your site at all.
2. The chat is off until you say otherwise — no script, no cookie, no request, and the screen says so rather than leaving you to find out.
3. The Zinn Digital® panel in your dashboard: this plugin's own guide, and the other things we run. No remote call, no tracking pixel, no image loaded from us.

== Changelog ==

= 1.3.6 =
* Translations: a word written in the wrong alphabet (for example a Korean word inside a Malayalam sentence, or an Urdu word ending a Punjabi one) is corrected in every language that had one. Each affected string was translated again and checked.

= 1.3.5 =
* The Pro edition's readme now links the Pro plugin's own page.

= 1.3.4 =
* Translations: re-translated the strings where a locale had dropped or altered a protected product name.

= 1.3.3 =
* Tested up to: 7.1 — the major version only, as WordPress.org's Plugin Check requires (7.1.1 was refused as invalid_tested_upto_minor).

= 1.3.2 =
* Tested up to WordPress 7.1.1.

= 1.3.1 =
* Maintenance release: the admin panel code this plugin shares with the other Zinn® plugins gained a layout fix for Zinn® Cache Engine. Nothing changes on this plugin's own screens.

= 1.3.0 =
* New: your own logo in the chat window. Upload it from the site's Live chat settings in your Zinn Digital® dashboard, and it appears beside your name at the top of the chat once the site is on Premium (included with Zinn® hosting).

= 1.2.0 =
* New: Zinn® Chat Pro, the paid edition — unlimited people answering, transcripts kept for ever, your own branding, saved replies, transfers, business hours, 5,000 AI answers a month, and a settings screen for choosing exactly which pages the chat appears on. One licence covers three of your sites, and it is included free with Zinn Digital® hosting.
* New: the settings screen names the edition you are running, read from the plugin header rather than a fixed string — so the paid edition no longer shows the free edition's name.
* New: the free edition explains what Pro adds, in your own language, with no remote call.

= 1.1.2 =
* The chat window now speaks your site's language: its buttons, placeholder, the "leave your email" prompt and "Powered by" follow the language your WordPress site is set to, in any of 58 languages, instead of always being English.

= 1.1.1 =
* Corrected: the plugin description and settings screen said the widget is under 5 KB. It is about 8 KB compressed, so they now say under 10 KB.
* Translations: every string this plugin's admin shows is now translated in every bundled language. A few strings the machine translator refused were shipping in English; they are now translated by hand.

= 1.1.0 =
* The widget's appearance now travels with the page instead of being fetched. The plugin caches your chat's greeting, colour, corner and team name when you save your key and once a day after that, so a visitor's browser makes no request at all on the chat's behalf until somebody opens it.
* If your key cannot be reached, the previous settings are kept rather than cleared.
* The chat now sits in the mirror corner on a right-to-left page, so "bottom right" means the side the reader ends on rather than the side they start from.

= 1.0.1 =
* The premium tier's branding removal is now listed where the tiers are described — it was the one paid capability this page did not mention.
* Clearer answer on what the free tier includes, and for how long.

= 1.0.0 =
* First release.
