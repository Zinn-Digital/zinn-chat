=== Zinn® Chat ===
Contributors: zinndigital
Plugin URI: https://zinndigital.com/wordpress-plugins/zinn-chat
Author: Neil Lock — CEO, Zinn Digital® Ltd
Author URI: https://zinndigital.com
Tags: live chat, helpdesk, support tickets, ai chatbot, woocommerce
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A help desk and AI assistant that runs on your own site: answers from your own pages with links, live chat, and support tickets.

== Description ==

Zinn® Chat puts a complete help desk inside your WordPress. An AI assistant answers your visitors from your own pages, with a link to the page it used; when it cannot, it says so honestly and hands the visitor to a person or takes their question as a support ticket. Your team answers live chats from a multi-chat inbox and tickets from wp-admin, and your customers follow their requests on a support page or in their WooCommerce account.

Everything runs on your own site and stays in your own database. The AI uses your own key with the provider you choose.

= An assistant that knows your whole site =

* **Reads everything, as visitors see it:** posts, pages and custom post types, WooCommerce products with prices, stock, variations, shipping and payment methods, forums (bbPress, BuddyBoss, wpForo), custom fields (ACF), and pages built with the block editor, Page Builder Sandwich, Elementor, Divi, Beaver Builder and Bricks.
* **Search by meaning, not just words:** your AI key builds a semantic index of your site. Without an embedding key it still searches by keyword, so it is never blind.
* **Keeps itself up to date:** new, edited and deleted content is picked up automatically, and a background check goes through the whole site every hour, however large it is.
* **Honest answers with links:** answers only from your pages, links the page it used, and says "I could not find that" instead of guessing, then offers a person or a ticket.
* **Answers in the visitor's language.**
* **"Where is my order?":** signed-in WooCommerce customers can ask about their own orders.
* **Bring your own AI key:** Google Gemini, OpenAI, Anthropic Claude, Mistral, OpenRouter, DeepSeek or any OpenAI-compatible service. The newest model is chosen automatically from the provider's own model list, and you can pick another.

= A test console that shows its working =

Ask the assistant questions in wp-admin and see the answer with every page it found: the address, title, when it was last updated, the passage it read and how well it matched. Pages that have not been updated for a year, or that disagree with a newer page on the same subject, are flagged, so you can find the stale page giving visitors a wrong answer, fix it, re-read it with one click, and ask again.

= Live chat that does not lose track =

* **Multi-chat inbox:** open several chats at once as tabs, side by side on a wide screen, each with its own draft. Waiting visitors go to the top with how long they have waited.
* **Phone and tablet view:** the inbox becomes one chat at a time, full screen, with a Focus mode that hides the rest of wp-admin.
* Internal notes, hand a chat back to the assistant, turn a chat into a ticket, email the visitor a copy.
* Sound and desktop alerts when somebody is waiting, and an email if nobody answers.

= Support tickets =

* Tickets in wp-admin with status, priority and who is handling them.
* A support page, a ticket form, a "my requests" list and a chat button, each as a shortcode, a block, and a module for Elementor, Divi, Beaver Builder and Bricks. Add "Submit a ticket" and "Chat with us" to any menu.
* A **Support** tab in the WooCommerce account area, and "Get help with this order" on every order.
* Email notifications both ways. Customers without an account follow their request through a private link; if it expires they can have a new one emailed.

= Fast by design =

The chat launcher is about 2 KB, printed inside the page, drawn after the page has finished loading, and fixed in the corner so it cannot shift your layout. The chat itself is fetched only when a visitor opens it. A page nobody chats on makes no request on the chat's behalf. Ticket forms load their small stylesheet only on the pages that show them.

= Privacy =

Nothing appears on your site until you turn the chat on. Visitors can be asked to agree before a chat or ticket, IP addresses are stored only in coded form unless you choose otherwise, and chats and tickets can be deleted automatically after a number of days you set. WordPress's own Export and Erase Personal Data tools include everything Zinn® Chat stores. Spam protection includes a hidden field, per-visitor limits and a block list, and loads nothing from another site.

= Connect to Zinn Digital® (optional) =

If Zinn Digital® hosts your site you can instead answer chats from the Zinn® app. Settings, Connect to Zinn Digital®.

= Shortcodes =

* `[zinn_chat_support]` — the whole support page: the form, the signed-in customer's requests, or a request opened from a private link.
* `[zinn_chat_ticket_form title="" subject="" button="" show_subject="yes"]` — just the form.
* `[zinn_chat_my_tickets]` — the signed-in customer's requests.
* `[zinn_chat_button label="Chat with us"]` — a button that opens the chat. Any link to `#zinn-chat`, or any element with the class `zinn-chat-open`, does the same.

= Zinn® Chat Pro =

An optional paid edition adds a Cloudflare Turnstile bot check on new chats and ticket forms, and a choice of exactly which pages show the chat. Everything described above is free and stays free.

== Installation ==

1. Install and activate the plugin. (If Zinn Digital® hosts your site, it is already there.)
2. Open **Zinn® Chat, Setup** and follow the checklist: turn the chat on, add your AI key, let the assistant read your site, and create your support page.
3. Open **Zinn® Chat, Inbox** to answer chats. Visitors can ask for a person while the Inbox is open.

== External services ==

**Your AI provider (only if you add a key).** When the assistant answers a visitor, the visitor's message, the recent conversation and the passages of your site that match it are sent to the AI provider you chose, with your key. When the site index is built or updated, the text of your published pages is sent to that provider to build the search index. Nothing is sent to any provider until you add its key. Providers and their terms: Google Gemini ([terms](https://ai.google.dev/gemini-api/terms), [privacy](https://policies.google.com/privacy)), OpenAI ([terms](https://openai.com/policies/services-agreement/), [privacy](https://openai.com/policies/privacy-policy/)), Anthropic ([terms](https://www.anthropic.com/legal/commercial-terms), [privacy](https://www.anthropic.com/legal/privacy)), Mistral ([terms](https://legal.mistral.ai/terms/commercial-terms-of-service/), [privacy](https://legal.mistral.ai/terms/privacy-policy/)), OpenRouter ([terms](https://openrouter.ai/terms), [privacy](https://openrouter.ai/privacy)), DeepSeek ([terms](https://cdn.deepseek.com/policies/en-US/deepseek-open-platform-terms-of-service.html), [privacy](https://cdn.deepseek.com/policies/en-US/deepseek-privacy-policy.html)), or a service you name yourself.

**Recommended models list (only if you tick it).** Settings, AI providers can fetch Zinn Digital®'s list of recommended models once a day from `https://api.zinndigital.com/v1/ai-model-catalogue`. The request carries no key, no site address and no content.

**Cloudflare Turnstile (Zinn® Chat Pro only, and only if you add Turnstile keys).** The chat and ticket forms load Cloudflare's Turnstile script, and each answer is checked with Cloudflare. The free edition never loads it. [Terms](https://www.cloudflare.com/website-terms/), [privacy](https://www.cloudflare.com/privacypolicy/).

**Zinn Digital® chat service (only in "Connect to Zinn Digital®" mode).** Your pages load `https://zinndigital.com/embed/zinn-chat.js` with your public chat key, and visitors' messages go to `https://api.zinndigital.com/v1/public/chat/…` so you can answer them from the Zinn® app. Once a day the plugin fetches your chat's appearance from the same service. [Terms](https://zinndigital.com/legal/terms), [privacy](https://zinndigital.com/legal/privacy).

**Licensing (Freemius).** The plugin includes the Freemius SDK for the optional Pro edition and product updates. Nothing is sent until you opt in on the screen shown after activation, or start a Pro trial or activate a licence. When you do, the SDK sends your site's URL, WordPress, PHP and plugin versions, language, and the administrator's name and email address to Freemius, and checks it periodically for licence status and updates. Before connecting it checks that the service is reachable by requesting `https://api.freemius.com/v1/ping.json`, which sends nothing about your site. You can opt out at any time from the plugin's Account page. [Terms](https://freemius.com/terms/), [privacy](https://freemius.com/privacy/).

== Frequently Asked Questions ==

= Do I need an AI key? =

For AI answers, yes: the assistant uses your own key, so you pay your provider directly and nothing goes through us. Without one, the chat still works for live chat and tickets.

= Which AI providers work? =

Google Gemini, OpenAI, Anthropic Claude, Mistral, OpenRouter, DeepSeek, and any OpenAI-compatible service, including one you run yourself. Meaning-based site search needs a provider that offers embeddings: Gemini, OpenAI, Mistral, OpenRouter or a compatible service.

= How does it know my content has changed? =

Saving, updating, trashing or deleting a post, product, forum reply or page-builder layout re-reads that item within a minute. An hourly background pass catches anything else, such as imports.

= The assistant gave a wrong answer. How do I find out why? =

Open **Zinn® Chat, Assistant**, ask the same question, and look at the sources. The page it used, and any out-of-date page, is shown with its last-updated date and an Edit button. Fix the page, press Re-read, and ask again.

= Does it work with WooCommerce? =

Yes. It reads your products, prices, stock, variations, shipping and payment methods, adds a Support tab to the account area, and tells signed-in customers about their own orders.

= Can I stop it showing on some pages? =

Return `false` from the `zinn_chat_should_render` filter.

= Will it slow my site down? =

No request is made on the chat's behalf until a visitor opens it, and the launcher cannot move your layout.

== Screenshots ==

1. The multi-chat Inbox: several chats at once, waiting visitors first.
2. The Assistant test console: the answer, and every page it came from, with out-of-date pages flagged.
3. A ticket in wp-admin.
4. The chat on a phone.
5. The Setup checklist.

== Changelog ==

= 2.0.0 =
* New: a complete help desk that runs on your own site. The AI assistant answers from a search index of your whole site (posts, pages, WooCommerce, forums, custom fields and page-builder content) built with your own AI key, links the page it used, and says when it does not know.
* New: the Assistant test console, with sources, match scores and out-of-date page warnings, and one-click re-reading.
* New: a multi-chat Inbox for live chat, with a phone and tablet view.
* New: support tickets in wp-admin, a support page, ticket form, "my requests" list and chat button as shortcodes, blocks and page-builder modules, a menu box, and a WooCommerce account tab.
* New: spam protection, consent, retention and personal-data export and erasure.
* Sites that used Zinn® Chat 1.x with a key keep working exactly as before, answered from the Zinn® app.
* Now runs on PHP 7.4 and WordPress 6.2 and later.


= 1.6.0 =
* Smaller download: the editable translation sources (.po) are no longer shipped; WordPress only ever loads the compiled .mo and .l10n.php files, which are unchanged.

= 1.5.0 =
* New: a "Go Pro" link beside Settings on the Plugins screen, and a "Pro features" section in this readme, so what Zinn® Chat Pro adds is easy to find. Neither appears in the Pro edition, and neither is a notice or makes a request.

= 1.4.0 =
* Updates install whenever you click Update, even months later: the download link is fetched fresh at install time instead of expiring in WordPress's saved update data.

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
* New: your own logo in the chat window. Upload it from the site's Live chat settings in your Zinn Digital® dashboard, and it appears beside your name at the top of the chat once the site is on Premium.

= 1.2.0 =
* New: Zinn® Chat Pro, the paid edition — unlimited people answering, transcripts kept for ever, your own branding, saved replies, transfers, business hours, 5,000 AI answers a month, and a settings screen for choosing exactly which pages the chat appears on. One licence covered three of your sites.
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
