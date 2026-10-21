/**
 * ویجت چت «دستیار فروش هوشمند kavexiron» — JS خالص، بدون هیچ وابستگی و بدون CDN.
 *
 * استایل کاملاً ایزوله است (همهٔ کلاس‌ها با پیشوند fy- زیر #fy-root) تا با هیچ
 * قالبی تداخل نکند. پیام‌ها امن رندر می‌شوند: اول escape، بعد لینک‌سازی URLها.
 */
(function () {
	'use strict';

	var cfg = window.ForushYarWidgetConfig || {};
	if (!cfg.restUrl || !cfg.nonce) {
		return;
	}

	var root = document.getElementById('fy-root');
	if (!root) {
		return;
	}
	root.setAttribute('dir', 'rtl');
	root.style.setProperty('--fy-primary', cfg.primary || '#C9A227');
	root.style.setProperty('--fy-primary-text', cfg.primaryText || '#111111');

	var chatHistory = [];
	var busy = false;
	var sid = null;
	try {
		sid = window.localStorage.getItem('fy_sid');
		if (!sid) {
			sid = 's' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
			window.localStorage.setItem('fy_sid', sid);
		}
		// بازیابی تاریخچهٔ چت از صفحه‌های قبلی (پارسا: حداکثر ۲۴ ساعت).
		var savedHist = window.localStorage.getItem('fy_hist');
		var savedTime = window.localStorage.getItem('fy_hist_time');
		if (savedHist && savedTime && (Date.now() - parseInt(savedTime, 10) < 24 * 3600 * 1000)) {
			var parsed = JSON.parse(savedHist);
			if (Array.isArray(parsed)) {
				chatHistory = parsed.slice(-20);
			}
		}
	} catch (e) {
		sid = 's' + Date.now().toString(36);
	}

	function saveHistory() {
		try {
			window.localStorage.setItem('fy_hist', JSON.stringify(chatHistory.slice(-20)));
			window.localStorage.setItem('fy_hist_time', String(Date.now()));
		} catch (e) {}
	}

	// رفرش بج سبد بدون رفرش صفحه (امیر).
	// چون wc-cart-fragments روی لندینگ لود نیست، بج را مستقیم با DOM آپدیت
	// می‌کنیم؛ trigger ووکامرس هم برای سازگاری با تم‌های دیگر می‌ماند.
	function refreshCartFragments(count) {
		try {
			if (typeof count === 'number' && count >= 0) {
				var sels = ['.dm-cart-count', '.cart-count', '.header-cart-count', '.wc-block-mini-cart__badge', '.cart-contents .count'];
				for (var si = 0; si < sels.length; si++) {
					var els = document.querySelectorAll(sels[si]);
					for (var ei = 0; ei < els.length; ei++) {
						els[ei].textContent = toFaDigits(count);
					}
				}
			}
			if (window.jQuery && window.jQuery(document.body)) {
				window.jQuery(document.body).trigger('wc_fragment_refresh');
			}
		} catch (e) {}
	}

	function cartCountOf(res) {
		if (res && res.data && typeof res.data.cart_count === 'number') {
			return res.data.cart_count;
		}
		return undefined;
	}

	// رندر تاریخچهٔ ذخیره‌شده هنگام باز شدن چت.
	function renderSavedHistory() {
		if (!chatHistory.length || log.children.length > 0) {
			return;
		}
		chatHistory.forEach(function (turn) {
			if (turn.role === 'user') {
				addMsg('user', turn.content, false);
			} else if (turn.role === 'assistant') {
				addMsg('assistant', renderRich(turn.content), true);
			}
		});
	}

	/* ---------- ساختار DOM ---------- */

	var btn = document.createElement('button');
	btn.type = 'button';
	btn.className = 'fy-btn';
	btn.setAttribute('aria-label', 'گفتگو با دستیار فروش');
	// لوگو با DOM امن ساخته می‌شود (نه innerHTML) تا XSS نشود.
	if (cfg.logoUrl) {
		var btnLogo = document.createElement('img');
		btnLogo.className = 'fy-btn-logo';
		btnLogo.src = cfg.logoUrl;
		btnLogo.alt = '';
		btnLogo.setAttribute('aria-hidden', 'true');
		// اگر لوگو لود نشد، img خراب نمایش داده نشود (دکمه تمیز می‌ماند).
		btnLogo.onerror = function () {
			btnLogo.setAttribute('data-broken', '1');
		};
		btn.appendChild(btnLogo);
	}
	var btnDot = document.createElement('span');
	btnDot.className = 'fy-btn-dot';
	btnDot.setAttribute('aria-hidden', 'true');
	btn.appendChild(btnDot);
	root.appendChild(btn);

	var panel = document.createElement('div');
	panel.className = 'fy-panel fy-hidden';
	panel.setAttribute('role', 'dialog');
	panel.setAttribute('aria-label', 'دستیار فروش هوشمند');
	root.appendChild(panel);

	var head = document.createElement('div');
	head.className = 'fy-head';
	panel.appendChild(head);

	var title = document.createElement('div');
	title.className = 'fy-title';
	// لوگوی kavexiron کنار تیتر.
	if (cfg.logoUrl) {
		var logo = document.createElement('img');
		logo.src = cfg.logoUrl;
		logo.alt = '';
		logo.className = 'fy-title-logo';
		title.appendChild(logo);
	}
	var titleText = document.createElement('span');
	titleText.textContent = cfg.title || 'دستیار فروش هوشمند kavexiron';
	title.appendChild(titleText);
	head.appendChild(title);

	var headActions = document.createElement('div');
	headActions.className = 'fy-head-actions';
	head.appendChild(headActions);

	// دکمه تغییر تم (روشن/تیره)
	var themeBtn = document.createElement('button');
	themeBtn.type = 'button';
	themeBtn.className = 'fy-theme-toggle';
	themeBtn.setAttribute('aria-label', 'تغییر تم روشن/تیره');
	themeBtn.textContent = '🌙';
	headActions.appendChild(themeBtn);

	// اعمال تم ذخیره‌شدهٔ کاربر — پیش‌فرض: روشن (مصوبهٔ فاز ۱ طراحی؛ تیره فقط با انتخاب کاربر)
	var savedTheme = null;
	try { savedTheme = window.localStorage.getItem('fy_theme'); } catch (e) {}
	if ( ! savedTheme ) {
		savedTheme = 'light';
	}
	root.setAttribute('data-theme', savedTheme);
	themeBtn.textContent = 'dark' === savedTheme ? '☀️' : '🌙';
	themeBtn.addEventListener('click', function() {
		var cur = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
		root.setAttribute('data-theme', cur);
		themeBtn.textContent = 'dark' === cur ? '☀️' : '🌙';
		try { window.localStorage.setItem('fy_theme', cur); } catch (e) {}
	});

	// دکمه گفتگوی جدید
	var newChatBtn = document.createElement('button');
	newChatBtn.type = 'button';
	newChatBtn.className = 'fy-theme-toggle';
	newChatBtn.setAttribute('aria-label', 'شروع گفتگوی جدید');
	newChatBtn.setAttribute('title', 'گفتگوی جدید');
	newChatBtn.textContent = '↺';
	headActions.appendChild(newChatBtn);
	newChatBtn.addEventListener('click', function() {
		chatHistory = [];
		try {
			window.localStorage.removeItem('fy_hist');
			// سشن جدید بساز تا کانتکست قدیمی استفاده نشود.
			sid = 's' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
			window.localStorage.setItem('fy_sid', sid);
		} catch (e) {}
		// پاک‌سازی بصری — مستقیم از DOM (نه متغیر log که ممکن است hoist نشده باشد).
		var logEl = root.querySelector('.fy-log');
		if (logEl) {
			logEl.innerHTML = '';
		}
		var w = cfg.welcome || 'سلام! من دستیار فروش هوشمندم. دنبال چه محصولی می‌گردید؟';
		addMsg('assistant', w, false);
		showQuickReplies();
	});

	var closeBtn = document.createElement('button');
	closeBtn.type = 'button';
	closeBtn.className = 'fy-close';
	closeBtn.setAttribute('aria-label', 'بستن گفتگو');
	closeBtn.textContent = '✕';
	headActions.appendChild(closeBtn);

	var log = document.createElement('div');
	log.className = 'fy-log';
	log.setAttribute('aria-live', 'polite');
	panel.appendChild(log);

	// کلیک روی نام محصول در فهرست متنی دستیار → نمایش کارت آن محصول.
	log.addEventListener('click', function (e) {
		var t = e.target;
		if (t && t.classList && t.classList.contains('fy-product-link')) {
			var pname = t.getAttribute('data-pname') || '';
			if (pname) {
				sendMessage('کارت محصول: ' + pname, { displayMessage: pname });
			}
		}
	});

	var form = document.createElement('form');
	form.className = 'fy-form';
	panel.appendChild(form);

	var input = document.createElement('input');
	input.type = 'text';
	input.className = 'fy-input';
	input.setAttribute('placeholder', 'پیام خود را بنویسید…');
	input.setAttribute('autocomplete', 'off');
	input.setAttribute('aria-label', 'متن پیام');
	// سقف سمت سرور ۱۰۰۰ کاراکتر است (class-rest.php) — ورودی بیشتر اجازه نده.
	input.setAttribute('maxlength', '1000');
	form.appendChild(input);

	var sendBtn = document.createElement('button');
	sendBtn.type = 'submit';
	sendBtn.className = 'fy-send';
	sendBtn.setAttribute('aria-label', 'ارسال پیام');
	sendBtn.innerHTML =
		'<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
		'<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>' +
		'</svg>';
	form.appendChild(sendBtn);

	var disclaimer = document.createElement('div');
	disclaimer.className = 'fy-disclaimer';
	disclaimer.textContent = 'پاسخ‌ها توسط هوش مصنوعی تولید می‌شود؛ تأیید نهایی با فروشگاه است. گفتگو برای بهبود خدمات ذخیره می‌شود.';
	panel.appendChild(disclaimer);

	/* ---------- رندر امن ---------- */

	function escapeHtml(s) {
		return String(s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	// تبدیل ارقام لاتین به فارسی برای نمایش (قیمت، موجودی، تعداد سبد).
	function toFaDigits(s) {
		return String(s).replace(/[0-9]/g, function (d) {
			return '۰۱۲۳۴۵۶۷۸۹'.charAt(parseInt(d, 10));
		});
	}

	// سطر فهرست محصول («- نام - قیمت تومان») → نام محصول کلیک‌شدنی برای نمایش کارت.
	function renderProductLine(line) {
		var m = line.match(/^-\s*(.+?)\s*[-–]\s*([\d,،۰-۹]+)\s*تومان(.*)$/);
		if (!m) {
			return escapeHtml(line);
		}
		var name = m[1].trim();
		var rest = (m[3] || '').trim();
		if (!name) {
			return escapeHtml(line);
		}
		var btn = '<button type="button" class="fy-product-link" data-pname="' + escapeHtml(name) + '">' + escapeHtml(name) + '</button>';
		var tail = escapeHtml(m[2]) + ' تومان' + (rest ? ' ' + escapeHtml(rest) : '');
		return '- ' + btn + ' - ' + tail;
	}

	// اول escape کامل، بعد بولد/خط‌شکن/URL (ضد XSS).
	function renderRich(text) {
		var lines = String(text).split('\n');
		var out = [];
		for (var i = 0; i < lines.length; i++) {
			out.push(renderProductLine(lines[i]));
		}
		var safe = out.join('<br>');
		// بولد مارک‌داون **متن**
		safe = safe.replace(/\*\*([^*<>]+)\*\*/g, '<strong>$1</strong>');
		// URLها لینک می‌شوند — پرانتز و نقطه‌گذاری انتهایی جزو لینک نیست
		safe = safe.replace(
			/(https?:\/\/[^\s<>"']+)/g,
			function (url) {
				var clean = url.replace(/[).,!?:;]+$/, '');
				var trail = url.slice(clean.length);
				return '<a class="fy-link" href="' + clean + '" target="_blank" rel="noopener">' + clean + '</a>' + trail;
			}
		);
		return safe;
	}

	function addMsg(role, html, isHtml) {
		var d = document.createElement('div');
		d.className = 'fy-msg fy-msg-' + role;
		// رنگ‌بندی بر اساس نوع پیام.
		if (role === 'assistant') {
			var txt = isHtml ? html.replace(/<[^>]*>/g, '') : html;
			if (/^✅/.test(txt)) {
				d.classList.add('fy-msg-success');
			} else if (/^(❌|⚠️|متأسف)/.test(txt)) {
				d.classList.add('fy-msg-error');
			} else if (/💡|پیشنهاد/.test(txt)) {
				d.classList.add('fy-msg-suggest');
			}
		}
		if (isHtml) {
			d.innerHTML = html;
		} else {
			d.textContent = html;
		}
		log.appendChild(d);
		// دکمه‌های امتیاز و کپی برای پاسخ‌های دستیار.
		if (role === 'assistant' && !d.classList.contains('fy-typing')) {
			var fb = document.createElement('div');
			fb.className = 'fy-feedback';
			var up = document.createElement('button');
			up.type = 'button';
			up.className = 'fy-fb-btn';
			up.textContent = '👍';
			up.title = 'پاسخ مفید بود';
			up.addEventListener('click', function () {
				up.disabled = true;
				down.disabled = true;
				up.classList.add('fy-fb-active');
				sendFeedback(1);
			});
			var down = document.createElement('button');
			down.type = 'button';
			down.className = 'fy-fb-btn';
			down.textContent = '👎';
			down.title = 'پاسخ مفید نبود';
			down.addEventListener('click', function () {
				up.disabled = true;
				down.disabled = true;
				down.classList.add('fy-fb-active');
				sendFeedback(-1);
			});
			var copy = document.createElement('button');
			copy.type = 'button';
			copy.className = 'fy-fb-btn';
			copy.textContent = '📋';
			copy.title = 'کپی متن';
			copy.addEventListener('click', function () {
				var txt = d.textContent || '';
				if (navigator.clipboard) {
					navigator.clipboard.writeText(txt);
					copy.textContent = '✓';
					setTimeout(function () { copy.textContent = '📋'; }, 1500);
				}
			});
			fb.appendChild(up);
			fb.appendChild(down);
			fb.appendChild(copy);
			d.appendChild(fb);
		}
		smartScroll();
		return d;
	}

	// ارسال بازخورد به سرور (برای بهبود مدل در آینده).
	function sendFeedback(score) {
		try {
			fetch(cfg.restUrl.replace(/\/chat$/, '/feedback'), {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-ForushYar-Nonce': cfg.nonce
				},
				body: JSON.stringify({ sid: sid, score: score, widget_nonce: cfg.nonce })
			});
		} catch (e) {}
	}

	function showTyping() {
		var d = document.createElement('div');
		d.className = 'fy-msg fy-msg-assistant fy-typing';
		d.innerHTML = '<span></span><span></span><span></span>';
		d.setAttribute('aria-label', 'در حال نوشتن…');
		log.appendChild(d);
		smartScroll();
		// بعد از ۱۵ ثانیه پیام صداقت نمایش بده.
		d._honestyTimer = setTimeout(function() {
			if (d.parentNode) {
				var note = document.createElement('div');
				note.className = 'fy-typing-note';
				note.textContent = 'کمی طول کشید، لطفاً صبر کنید…';
				d.appendChild(note);
			}
		}, 15000);
		return d;
	}

	function smartScroll() {
		// فقط اگر کاربر ته چت است اسکرول کن.
		var atBottom = log.scrollHeight - log.scrollTop - log.clientHeight < 80;
		if (atBottom) {
			log.scrollTop = log.scrollHeight;
		}
	}

	function forceScroll() {
		// بعد از ارسال پیام کاربر یا رسیدن جواب، همیشه برو ته چت.
		var go = function () {
			log.scrollTop = log.scrollHeight;
		};
		go();
		if (typeof requestAnimationFrame !== 'undefined') {
			requestAnimationFrame(go);
		}
		setTimeout(go, 150);
	}

	function hideTyping(d) {
		if (d._honestyTimer) {
			clearTimeout(d._honestyTimer);
		}
		if (d.parentNode) {
			d.parentNode.removeChild(d);
		}
	}

	/* ---------- کارت محصول + دکمهٔ افزودن به سبد ---------- */

	// رندر اقلام سبد با دکمه × برای حذف.
	function renderCartItems(items) {
		if (!items || !items.length) {
			return null;
		}
		var wrap = document.createElement('div');
		wrap.className = 'fy-cart-items';
		items.forEach(function(item) {
			var row = document.createElement('div');
			row.className = 'fy-cart-item';
			var name = document.createElement('span');
			name.className = 'fy-cart-item-name';
			name.textContent = (item.name || '') + ' ×' + toFaDigits(item.quantity || 1);
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'fy-cart-remove';
			btn.textContent = '×';
			btn.setAttribute('aria-label', 'حذف از سبد');
			btn.addEventListener('click', function() {
				btn.disabled = true;
				sendMessage('(حذف از سبد: ' + (item.cart_key || '') + ')', { displayMessage: 'حذف ' + (item.name || '') + ' از سبد' });
			});
			row.appendChild(name);
			row.appendChild(btn);
			wrap.appendChild(row);
		});
		return wrap;
	}

	function renderProducts(products) {
		if (!products || !products.length) {
			return null;
		}
		var shown = products.slice(0, 10);
		// ارزان‌ترینِ واقعیِ همین مجموعه — کارت‌ها بر اساس ارتباط مرتب‌اند،
		// نه قیمت؛ پس «اولین کارت» لزوماً ارزان‌ترین نیست (باگ برچسب اقتصادی‌ترین).
		var minPrice = null;
		shown.forEach(function (q) {
			var qp = parseFloat(q.price);
			if (!isNaN(qp) && qp > 0 && (minPrice === null || qp < minPrice)) {
				minPrice = qp;
			}
		});
		var wrap = document.createElement('div');
		wrap.className = 'fy-products';
		shown.forEach(function (p, idx) {
			var card = document.createElement('div');
			card.className = 'fy-product';
			// انیمیشن ورود تدریجی (stagger)
			card.style.animationDelay = (idx * 60) + 'ms';

			var outOfStock = p.in_stock === false;

			// --- بج‌های اعتماد روی عکس ---
			var imgWrap = document.createElement('div');
			imgWrap.className = 'fy-product-imgwrap';
			if (p.image) {
				var img = document.createElement('img');
				img.className = 'fy-product-img';
				img.src = p.image;
				img.alt = p.name || '';
				img.loading = 'lazy';
				imgWrap.appendChild(img);
			}
			// حداکثر ۲ بج روی کارت (اولویت: ناموجود > تخفیف > پرفروش)
			var badgeCount = 0;
			// بج ناموجود (بالاترین اولویت)
			if (outOfStock) {
				var oosBadge = document.createElement('span');
				oosBadge.className = 'fy-badge fy-badge-oos';
				oosBadge.textContent = 'ناموجود';
				imgWrap.appendChild(oosBadge);
				card.classList.add('fy-product-oos');
				badgeCount++;
			}
			// بج تخفیف
			if (p.on_sale && p.regular_price && p.sale_price && badgeCount < 2) {
				var saleBadge = document.createElement('span');
				saleBadge.className = 'fy-badge fy-badge-sale';
				saleBadge.textContent = 'تخفیف‌دار';
				imgWrap.appendChild(saleBadge);
				badgeCount++;
			}
			// بج پرفروش
			if (p.is_bestseller && badgeCount < 2) {
				var bestBadge = document.createElement('span');
				bestBadge.className = 'fy-badge fy-badge-best';
				bestBadge.textContent = 'پرفروش';
				imgWrap.appendChild(bestBadge);
				badgeCount++;
			}
			// کلیک روی عکس/نام = کوییک‌ویو
			imgWrap.style.cursor = 'pointer';
			imgWrap.addEventListener('click', function () { openQuickView(p); });
			card.appendChild(imgWrap);

			// --- نام (کلیک = کوییک‌ویو) ---
			var name = document.createElement('div');
			name.className = 'fy-product-name fy-clickable';
			name.textContent = p.name || '';
			name.addEventListener('click', function () { openQuickView(p); });
			card.appendChild(name);

			// --- تیزر یک‌خطی از short_desc ---
			if (p.short_desc) {
				var teaser = document.createElement('div');
				teaser.className = 'fy-product-teaser';
				var td = String(p.short_desc).replace(/\s+/g, ' ').trim();
				// حذف ایموجی‌ها از توضیحات (آیکن نامربوط).
				td = td.replace(/[\u{1F300}-\u{1F9FF}\u{2600}-\u{27BF}\u{2B00}-\u{2BFF}]/gu, '').trim();
				teaser.textContent = td.length > 60 ? td.slice(0, 60) + '…' : td;
				if (teaser.textContent) {
					card.appendChild(teaser);
				}
			}

			// --- قیمت با لنگر ---
			var priceWrap = document.createElement('div');
			priceWrap.className = 'fy-product-pricewrap';
			if (p.on_sale && p.regular_price) {
				var oldPrice = document.createElement('span');
				oldPrice.className = 'fy-price-old';
				oldPrice.textContent = toFaDigits(p.regular_price);
				priceWrap.appendChild(oldPrice);
			}
			var price = document.createElement('div');
			price.className = 'fy-product-price' + (p.on_sale ? ' fy-price-sale' : '');
			price.textContent = toFaDigits(p.price_text || p.price);
			priceWrap.appendChild(price);
			// تگ اقتصادی‌ترین فقط برای کارتی که واقعاً کمترین قیمت این مجموعه را دارد.
			if (minPrice !== null && parseFloat(p.price) === minPrice && products.length > 1 && !p.on_sale) {
				var ecoTag = document.createElement('span');
				ecoTag.className = 'fy-eco-tag';
				ecoTag.textContent = 'اقتصادی‌ترین';
				priceWrap.appendChild(ecoTag);
			}
			card.appendChild(priceWrap);

			// --- هشدار موجودی کم ---
			if (!outOfStock && p.stock_qty !== null && p.stock_qty !== undefined && p.stock_qty <= 3 && p.stock_qty > 0) {
				var lowStock = document.createElement('div');
				lowStock.className = 'fy-low-stock';
				lowStock.textContent = 'فقط ' + toFaDigits(p.stock_qty) + ' عدد مانده!';
				card.appendChild(lowStock);
			}

			var actions = document.createElement('div');
			actions.className = 'fy-product-actions';

			// دکمه افزودن به سبد
			var addBtn = document.createElement('button');
			addBtn.type = 'button';
			addBtn.className = 'fy-add-cart';
			if (outOfStock) {
				addBtn.disabled = true;
				addBtn.textContent = 'ناموجود';
			} else {
				addBtn.textContent = '🛒 افزودن به سبد';
			}
			addBtn.addEventListener('click', function () {
				if (addBtn.disabled) {
					return;
				}
				addBtn.disabled = true;
				addBtn.textContent = 'در حال افزودن…';
				var productName = p.name || '';
				sendMessage(
					'«' + productName + '» (شناسه ' + p.id + ') را به سبد خرید اضافه کن',
					{
						displayMessage: '🛒 افزودن «' + productName + '» به سبد…',
						onDone: function (success, res, cartAdded) {
							if (cartAdded) {
								addBtn.textContent = '✓ در سبد خرید';
								addBtn.disabled = true;
								addBtn.style.opacity = '0.7';
								addBtn.style.cursor = 'default';
								card.classList.add('fy-added-flash');
								setTimeout(function () { card.classList.remove('fy-added-flash'); }, 1200);
								if (cfg.cartUrl && !actions.querySelector('.fy-checkout-link')) {
									var checkoutLink = document.createElement('a');
									checkoutLink.className = 'fy-checkout-link';
									checkoutLink.href = cfg.cartUrl;
									checkoutLink.title = 'تکمیل خرید';
									checkoutLink.setAttribute('aria-label', 'تکمیل خرید');
									checkoutLink.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>';
									actions.appendChild(checkoutLink);
								}
								refreshCartFragments(cartCountOf(res));
							} else {
								addBtn.disabled = false;
								addBtn.textContent = '🛒 افزودن به سبد';
							}
						}
					}
				);
			});
			actions.appendChild(addBtn);

			// دکمه «درباره این محصول بپرس» 💬
			var askBtn = document.createElement('button');
			askBtn.type = 'button';
			askBtn.className = 'fy-ask-btn';
			askBtn.textContent = '💬';
			askBtn.title = 'درباره این محصول بپرس';
			askBtn.setAttribute('aria-label', 'درباره ' + (p.name || 'این محصول') + ' بپرس');
			askBtn.addEventListener('click', function () {
				var q = 'درباره «' + (p.name || '') + '» بیشتر بگو';
				if (typeof input !== 'undefined' && input) {
					input.value = q;
					input.focus();
				}
				sendMessage(q, {});
			});
			actions.appendChild(askBtn);

			// آیکن توضیحات محصول (امیر: به‌جای متن «مشاهده»، آیکن با تول‌تیپ)
			if (p.url && /^(https?:\/\/|\/)/i.test(p.url)) {
				var view = document.createElement('a');
				view.className = 'fy-view';
				view.href = p.url;
				view.target = '_blank';
				view.rel = 'noopener';
				view.title = 'توضیحات محصول';
				view.setAttribute('aria-label', 'توضیحات ' + (p.name || 'محصول'));
				view.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>';
				actions.appendChild(view);
			}

			card.appendChild(actions);
			wrap.appendChild(card);
		});
		return wrap;
	}

	/* ---------- کوییک‌ویو داخل چت ---------- */

	function openQuickView(p) {
		// حذف مودال قبلی اگر هست
		var old = root.querySelector('.fy-qv-overlay');
		if (old) { old.remove(); }

		var overlay = document.createElement('div');
		overlay.className = 'fy-qv-overlay';
		// بستن متمرکز: هر سه مسیر (بک‌دراپ، دکمه ×، ESC) هم اورلی را حذف
		// می‌کنند هم شنوندهٔ keydown را — تا شنوندهٔ یتیم انباشته نشود.
		var escHandler = function (e) {
			if (e.key === 'Escape') { closeQv(); }
		};
		function closeQv() {
			document.removeEventListener('keydown', escHandler);
			overlay.remove();
		}
		overlay.addEventListener('click', function (e) {
			if (e.target === overlay) { closeQv(); }
		});

		var modal = document.createElement('div');
		modal.className = 'fy-qv-modal';
		modal.setAttribute('role', 'dialog');
		modal.setAttribute('aria-label', 'مشخصات محصول');

		var close = document.createElement('button');
		close.type = 'button';
		close.className = 'fy-qv-close';
		close.textContent = '✕';
		close.setAttribute('aria-label', 'بستن');
		close.addEventListener('click', closeQv);
		modal.appendChild(close);

		if (p.image) {
			var img = document.createElement('img');
			img.className = 'fy-qv-img';
			img.src = p.image;
			img.alt = p.name || '';
			modal.appendChild(img);
		}

		var name = document.createElement('div');
		name.className = 'fy-qv-name';
		name.textContent = p.name || '';
		modal.appendChild(name);

		if (p.short_desc) {
			var desc = document.createElement('div');
			desc.className = 'fy-qv-desc';
			desc.textContent = p.short_desc;
			modal.appendChild(desc);
		}

		var priceRow = document.createElement('div');
		priceRow.className = 'fy-qv-price';
		if (p.on_sale && p.regular_price) {
			var oldP = document.createElement('span');
			oldP.className = 'fy-price-old';
			oldP.textContent = toFaDigits(p.regular_price);
			priceRow.appendChild(oldP);
		}
		var pr = document.createElement('span');
		pr.className = 'fy-product-price' + (p.on_sale ? ' fy-price-sale' : '');
		pr.textContent = toFaDigits(p.price_text || p.price);
		priceRow.appendChild(pr);
		modal.appendChild(priceRow);

		if (p.in_stock === false) {
			var oos = document.createElement('div');
			oos.className = 'fy-qv-oos';
			oos.textContent = 'ناموجود';
			modal.appendChild(oos);
		} else if (p.stock_qty !== null && p.stock_qty !== undefined && p.stock_qty <= 3 && p.stock_qty > 0) {
			var ls = document.createElement('div');
			ls.className = 'fy-low-stock';
			ls.textContent = 'فقط ' + toFaDigits(p.stock_qty) + ' عدد مانده!';
			modal.appendChild(ls);
		}

		var qvActions = document.createElement('div');
		qvActions.className = 'fy-qv-actions';

		if (p.in_stock !== false) {
			var qvAdd = document.createElement('button');
			qvAdd.type = 'button';
			qvAdd.className = 'fy-add-cart fy-qv-add';
			qvAdd.textContent = '🛒 افزودن به سبد';
			qvAdd.addEventListener('click', function () {
				overlay.remove();
				sendMessage('«' + (p.name || '') + '» (شناسه ' + p.id + ') را به سبد خرید اضافه کن', {
					displayMessage: '🛒 افزودن «' + (p.name || '') + '» به سبد…',
					onDone: function (success, res, cartAdded) {
						if (cartAdded) { refreshCartFragments(cartCountOf(res)); }
					}
				});
			});
			qvActions.appendChild(qvAdd);
		}

		if (p.url && /^(https?:\/\/|\/)/i.test(p.url)) {
			var qvView = document.createElement('a');
			qvView.className = 'fy-view';
			qvView.href = p.url;
			qvView.target = '_blank';
			qvView.rel = 'noopener';
			qvView.textContent = 'صفحه محصول ↗';
			qvActions.appendChild(qvView);
		}
		modal.appendChild(qvActions);

		overlay.appendChild(modal);
		root.appendChild(overlay);

		// بستن با ESC (شنونده در closeQv پاک می‌شود).
		document.addEventListener('keydown', escHandler);
	}

	/* ---------- باز/بسته ---------- */

	var opened = false;
	var welcomed = false;
	var userHasTyped = false;
	var chipsWrap = null;
	var helpTimer = null;

	// دکمه‌های پاسخ سریع — زیر پیام خوش‌آمد؛ با اولین ارسال کاربر حذف می‌شوند.
	function showQuickReplies() {
		var list = cfg.quickReplies;
		if (!cfg.quickRepliesEnabled || !list || !list.length) {
			return;
		}
		if (chipsWrap && chipsWrap.parentNode) {
			return;
		}
		chipsWrap = document.createElement('div');
		chipsWrap.className = 'fy-chips';
		list.forEach(function (q) {
			var c = document.createElement('button');
			c.type = 'button';
			c.className = 'fy-chip';
			c.textContent = q;
			c.addEventListener('click', function () {
				input.value = q;
				if (chipsWrap && chipsWrap.parentNode) {
					log.removeChild(chipsWrap);
				}
				chipsWrap = null;
				if (form.requestSubmit) {
					form.requestSubmit();
				} else {
					form.dispatchEvent(new Event('submit', { cancelable: true }));
				}
			});
			chipsWrap.appendChild(c);
		});
		log.appendChild(chipsWrap);
		smartScroll();
	}

	function toggle(force) {
		opened = typeof force === 'boolean' ? force : !opened;
		panel.classList.toggle('fy-hidden', !opened);
		if (opened) {
			// با باز شدن چت، لیبل کنار دکمه حذف می‌شود.
			var lbl = root.querySelector('.fy-label');
			if (lbl) {
				lbl.remove();
			}
			// پیام خوش‌آمد + پاسخ‌های سریع — فقط یک بار در هر بارگذاری صفحه.
			// اگر تاریخچهٔ ذخیره‌شده هست، آن را نشان بده و خوش‌آمد تکراری نگو.
			if (!welcomed) {
				welcomed = true;
				if (chatHistory.length) {
					renderSavedHistory();
				} else {
					var w = cfg.welcome || 'سلام! من دستیار فروش هوشمندم. دنبال چه محصولی می‌گردید؟';
					addMsg('assistant', w, false);
					showQuickReplies();
					// بعد از ۱۰ ثانیه سکوت، پیشنهاد راهنما.
					helpTimer = setTimeout(function() {
						helpTimer = null;
						if (!userHasTyped) {
							var chip = document.createElement('button');
							chip.type = 'button';
							chip.className = 'fy-help-chip';
							chip.textContent = '📖 راهنمای استفاده';
							chip.addEventListener('click', function() {
								chip.remove();
								sendMessage('راهنما', { displayMessage: 'راهنما' });
							});
							log.appendChild(chip);
							smartScroll();
						}
					}, 10000);
				}
			}
			input.focus();
		} else {
			// توقف تایمر چیپ راهنما تا بعد از بسته شدن شلیک نکند؛
			// بازگشت فوکوس به دکمهٔ شناور اگر فوکوس داخل پنل بود.
			if (helpTimer) {
				clearTimeout(helpTimer);
				helpTimer = null;
			}
			if (document.activeElement && panel.contains(document.activeElement)) {
				btn.focus();
			}
		}
	}
	btn.addEventListener('click', function () { toggle(); });
	closeBtn.addEventListener('click', function () { toggle(false); });

	// بستن چت با ESC.
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && opened) {
			toggle(false);
		}
	});

	/* ---------- لیبل دائمی کنار دکمه (جایگزین حباب موقت) ---------- */

	function showButtonLabel() {
		if (root.querySelector('.fy-label')) {
			return;
		}
		// سبد رها شده اولویت دارد.
		var text = cfg.proactiveNudgeText || 'به دنبال چه محصولی هستید؟ بفرمایید تا پیدایش کنم 🔍';
		if (cfg.abandonedCart && cfg.abandonedCart.count > 0) {
			text = '🛒 سبد خرید شما منتظر شماست! ' + toFaDigits(cfg.abandonedCart.count) + ' قلم در آن قرار دارد 👀';
		}
		var l = document.createElement('div');
		l.className = 'fy-label';
		l.setAttribute('role', 'button');
		l.setAttribute('tabindex', '0');
		l.setAttribute('aria-label', text);
		var t = document.createElement('span');
		t.className = 'fy-label-text';
		// ذره‌بین متحرک: ایموجی 🔍 را در span جدا می‌پیچیم تا انیمیشن جستجو بگیرد.
		var emojiIdx = text.lastIndexOf('🔍');
		if (emojiIdx !== -1) {
			t.appendChild(document.createTextNode(text.substring(0, emojiIdx)));
			var em = document.createElement('span');
			em.className = 'fy-label-emoji';
			em.textContent = '🔍';
			t.appendChild(em);
			var rest = text.substring(emojiIdx + 2);
			if (rest) { t.appendChild(document.createTextNode(rest)); }
		} else {
			t.textContent = text;
		}
		l.appendChild(t);
		var x = document.createElement('button');
		x.type = 'button';
		x.className = 'fy-label-close';
		x.setAttribute('aria-label', 'بستن');
		x.textContent = '✕';
		x.addEventListener('click', function (e) {
			e.stopPropagation();
			l.remove();
		});
		l.appendChild(x);
		function openFromLabel() {
			l.remove();
			toggle(true);
		}
		l.addEventListener('click', openFromLabel);
		l.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' || e.key === ' ') {
				openFromLabel();
			}
		});
		l.classList.add('fy-label-attention');
		root.appendChild(l);
	}

	// لیبل دائمی — کمی بعد از لود صفحه ظاهر می‌شود و تا بسته شدن/باز شدن چت می‌ماند.
	var labelDelay = Math.max(0, parseInt(cfg.proactiveDelay, 10) || 0);
	window.setTimeout(function () {
		if (opened) {
			return;
		}
		if (cfg.proactiveOpen) {
			toggle(true);
		} else if (!root.querySelector('.fy-label')) {
			showButtonLabel();
		}
	}, Math.min(Math.max(labelDelay, 1), 120) * 1000);

	/* ---------- ارسال ---------- */

	function errText(status) {
		var msg;
		if (status === 429) {
			msg = 'تعداد درخواست‌ها زیاد است؛ کمی صبر کنید و دوباره تلاش کنید.';
		} else if (status === 403) {
			msg = 'دسترسی مجاز نیست؛ صفحه را رفرش کنید.';
		} else {
			msg = 'اتصال برقرار نشد؛ دوباره تلاش کنید.';
		}
		return msg + '\nمشکل ادامه داشت؟ با پشتیبانی در تماس باشید.';
	}

	function sendMessage(text, opts) {
		var msg = String(text || '').trim();
		if (!msg || busy) {
			return;
		}
		// کاربر تایپ کرد — چیپ راهنما دیگر نشان داده نشود.
		userHasTyped = true;
		opts = opts || {};
		busy = true;
		if (typeof sendBtn !== 'undefined' && sendBtn) sendBtn.disabled = true;
		input.value = '';
		// حذف دکمه‌های پاسخ سریع با اولین پیام کاربر.
		if (chipsWrap && chipsWrap.parentNode) {
			log.removeChild(chipsWrap);
			chipsWrap = null;
		}
		// پیام نمایشی (اگر متفاوت از پیام ارسالی باشد).
		var displayMsg = opts.displayMessage !== undefined ? opts.displayMessage : msg;
		if (displayMsg) {
			addMsg('user', displayMsg, false);
			forceScroll();
		}
		var typing = showTyping();
		forceScroll();
		// شناسه سشن در لحظه ارسال — برای نادیده گرفتن جواب‌های چت قبلی.
		var reqSid = sid;

		// مهلت ۶۰ ثانیه‌ای: سرور گیرکرده نباید ویجت را برای همیشه قفل کند.
		// با abort، مسیر catch موجود پیام آفلاین را نشان می‌دهد و busy=false می‌شود.
		var fetchCtl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
		var fetchTimer = setTimeout(function () {
			if (fetchCtl) {
				fetchCtl.abort();
			}
		}, 60000);

		fetch(cfg.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			signal: fetchCtl ? fetchCtl.signal : undefined,
			headers: {
				'Content-Type': 'application/json',
				'X-ForushYar-Nonce': cfg.nonce
			},
			body: JSON.stringify({
				message: msg,
				history: chatHistory.slice(-10),
				sid: sid,
				widget_nonce: cfg.nonce
			})
		})
			.then(function (r) {
				return r.json().then(function (data) {
					return { status: r.status, ok: r.ok, data: data };
				});
			})
			.then(function (res) {
				clearTimeout(fetchTimer);
				// اگر سشن عوض شده (گفتگوی جدید)، جواب قدیمی را نادیده بگیر.
				if (reqSid !== sid) {
					if (typing.parentNode) {
						log.removeChild(typing);
					}
					busy = false;
					if (typeof sendBtn !== 'undefined' && sendBtn) sendBtn.disabled = false;
					return;
				}
				hideTyping(typing);
				var success = res.ok && res.data && res.data.reply;
				// برای دکمه «افزودن به سبد»: فقط وقتی موفق است که ابزار add_to_cart واقعاً صدا زده و موفق شده باشد.
				var cartAdded = false;
				if ( success && res.data.tools && Array.isArray(res.data.tools) ) {
					for ( var ti = 0; ti < res.data.tools.length; ti++ ) {
						var tt = res.data.tools[ti];
						if ( tt && tt.name === 'add_to_cart' && tt.ok ) {
							cartAdded = true;
							break;
						}
					}
				}
				if (success) {
					addMsg('assistant', renderRich(res.data.reply), true);
					forceScroll();
					var cards = renderProducts(res.data.products);
					if (cards) {
						log.appendChild(cards);
						smartScroll();
					}
					// رندر اقلام سبد با دکمه ×.
					if (res.data.cart_items && res.data.cart_items.length) {
						var cartList = renderCartItems(res.data.cart_items);
						if (cartList) {
							log.appendChild(cartList);
							smartScroll();
						}
					}
					// اگر محصول به سبد اضافه شد، بج سبد هدر را بدون رفرش آپدیت کن.
					if (cartAdded) {
						refreshCartFragments(cartCountOf(res));
					}
					// در تاریخچه، پیام نمایشی دوستانه ذخیره می‌شود نه دستور داخلی ابزار.
					chatHistory.push({ role: 'user', content: displayMsg || msg });
					chatHistory.push({ role: 'assistant', content: res.data.reply });
					saveHistory();
				} else if (res.data && res.data.message) {
					addMsg('assistant', res.data.message, false);
				} else {
					addMsg('assistant', errText(res.status), false);
				}
				busy = false;
				if (typeof sendBtn !== 'undefined' && sendBtn) sendBtn.disabled = false;
				if (opts.onDone) {
					opts.onDone(success, res, cartAdded);
				}
			})
			.catch(function () {
				clearTimeout(fetchTimer);
				hideTyping(typing);
				addMsg('assistant', errText(0), false);
				busy = false;
				if (typeof sendBtn !== 'undefined' && sendBtn) sendBtn.disabled = false;
				if (opts.onDone) {
					opts.onDone(false, null);
				}
			});
	}

	form.addEventListener('submit', function (e) {
		e.preventDefault();
		sendMessage(input.value);
	});
})();
