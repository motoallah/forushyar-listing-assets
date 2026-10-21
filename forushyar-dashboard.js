/* داشبورد مشتری «دستیار فروش هوشمند kavexiron» — چت + جستجو + افزودن به سبد */
(function () {
	'use strict';
	var cfg = window.forushyarDashboard || {};
	if (!cfg.restUrl || !cfg.nonce) { return; }
	var T = cfg.i18n || {};

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function faDigits(s) {
		return String(s).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.charAt(+d); });
	}
	function rich(text) {
		return esc(text).replace(/\n/g, '<br>');
	}

	var sid = 'dash_' + Math.random().toString(36).slice(2, 12);
	var busy = false;

	function post(message) {
		return fetch(cfg.restUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-ForushYar-Nonce': cfg.nonce
			},
			body: JSON.stringify({ message: message, sid: sid, widget_nonce: cfg.nonce, history: [] })
		}).then(function (r) { return r.json().then(function (d) { return { status: r.status, ok: r.ok, data: d }; }); });
	}

	/* ---------- کارت محصول ---------- */
	function cardHTML(p) {
		var id = p.id || 0;
		var img = p.image ? '<a href="' + esc(p.url || '#') + '" class="fy-dash-card-img"><img src="' + esc(p.image) + '" alt="' + esc(p.name || '') + '" loading="lazy"></a>' : '';
		var price = p.price_text || '';
		if (p.on_sale && p.sale_price) {
			price = '<span class="fy-dash-price-sale">' + esc(p.sale_price) + '</span><del>' + esc(p.regular_price || '') + '</del>';
		} else {
			price = esc(price);
		}
		price += ' تومان';
		return '<div class="fy-dash-card" data-pid="' + id + '">' + img +
			'<div class="fy-dash-card-body">' +
			'<a href="' + esc(p.url || '#') + '" class="fy-dash-card-name">' + esc(p.name || '') + '</a>' +
			'<div class="fy-dash-card-price">' + price + '</div>' +
			'<button type="button" class="fy-dash-add" data-pid="' + id + '" data-name="' + esc(p.name || '') + '">' + esc(T.addToCart || 'افزودن به سبد') + '</button>' +
			'</div></div>';
	}

	function wireAdds(root, afterAdd) {
		var btns = root.querySelectorAll('.fy-dash-add');
		for (var i = 0; i < btns.length; i++) {
			(function (btn) {
				btn.addEventListener('click', function () {
					if (btn.disabled) { return; }
					btn.disabled = true;
					var pid = btn.getAttribute('data-pid');
					var nm = btn.getAttribute('data-name') || '';
					post('«' + nm + '» (شناسه ' + pid + ') را به سبد خرید اضافه کن').then(function (res) {
						if (res.ok && res.data && res.data.reply) {
							btn.textContent = T.added || 'به سبد اضافه شد ✓';
							if (afterAdd) { afterAdd(res.data.reply); }
						} else {
							btn.disabled = false;
						}
					}).catch(function () { btn.disabled = false; });
				});
			})(btns[i]);
		}
	}

	/* ---------- گفتگو ---------- */
	var log = document.getElementById('fy-dash-chat-log');
	var input = document.getElementById('fy-dash-chat-q');
	var sendBtn = document.getElementById('fy-dash-chat-send');

	function addMsg(who, html) {
		if (!log) { return; }
		var d = document.createElement('div');
		d.className = 'fy-dash-msg fy-dash-msg-' + who;
		d.innerHTML = html;
		log.appendChild(d);
		log.scrollTop = log.scrollHeight;
	}

	function addProducts(products) {
		if (!products || !products.length || !log) { return; }
		var wrap = document.createElement('div');
		wrap.className = 'fy-dash-grid fy-dash-grid-chat';
		wrap.innerHTML = products.map(cardHTML).join('');
		log.appendChild(wrap);
		log.scrollTop = log.scrollHeight;
		wireAdds(wrap, function (reply) {
			addMsg('assistant', rich(reply));
		});
	}

	function sendChat(text) {
		text = (text || '').trim();
		if (!text || busy) { return; }
		busy = true;
		addMsg('user', rich(text));
		var typing = document.createElement('div');
		typing.className = 'fy-dash-msg fy-dash-msg-assistant fy-dash-typing';
		typing.textContent = T.thinking || '…';
		log.appendChild(typing);
		log.scrollTop = log.scrollHeight;
		post(text).then(function (res) {
			if (typing.parentNode) { typing.parentNode.removeChild(typing); }
			if (res.ok && res.data && res.data.reply) {
				addMsg('assistant', rich(res.data.reply));
				addProducts(res.data.products);
			} else {
				addMsg('assistant', esc(T.error || 'خطا'));
			}
			busy = false;
		}).catch(function () {
			if (typing.parentNode) { typing.parentNode.removeChild(typing); }
			addMsg('assistant', esc(T.error || 'خطا'));
			busy = false;
		});
	}

	if (sendBtn && input) {
		sendBtn.addEventListener('click', function () { sendChat(input.value); input.value = ''; input.focus(); });
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') { sendChat(input.value); input.value = ''; }
		});
	}
	// پیام خوش‌آمد.
	if (log && cfg.welcome) {
		addMsg('assistant', rich(cfg.welcome));
	}

	/* ---------- جستجو ---------- */
	var sForm = document.getElementById('fy-dash-search');
	var sInput = document.getElementById('fy-dash-search-q');
	var sBtn = document.getElementById('fy-dash-search-btn');
	var sRes = document.getElementById('fy-dash-search-results');

	function doSearch() {
		var q = (sInput.value || '').trim();
		if (!q || !sRes) { return; }
		sRes.innerHTML = '<p class="fy-dash-note">' + esc(T.thinking || '…') + '</p>';
		post(q).then(function (res) {
			var products = (res.ok && res.data && res.data.products) || [];
			if (!products.length) {
				sRes.innerHTML = '<p class="fy-dash-note">' + esc(T.noResult || 'یافت نشد') + '</p>';
				return;
			}
			sRes.innerHTML = products.map(cardHTML).join('');
			wireAdds(sRes, null);
		}).catch(function () {
			sRes.innerHTML = '<p class="fy-dash-note">' + esc(T.error || 'خطا') + '</p>';
		});
	}
	if (sForm) {
		sForm.addEventListener('submit', doSearch);
		if (sBtn) { sBtn.addEventListener('click', doSearch); }
	}

	/* دکمه‌های «افزودن به سبد» بخش پیشنهادها (سرور-رندر). */
	wireAdds(document, function (reply) {
		if (log) { addMsg('assistant', rich(reply)); }
	});
})();
