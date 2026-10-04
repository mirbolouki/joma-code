/* ═════════════════════════════════════════════════════════════════════
 *  جوما — سامانهٔ مدیریت کلینیک | اسکریپت رابط کاربری فاز ۱
 *  بدون هیچ کتابخانهٔ بیرونی (بدون jQuery / بدون CDN)
 *
 *  امکانات:
 *   ۱) باز و بستهٔ منوی موبایل
 *   ۲) نشانگر قدرت رمز (فقط نمایشی)
 *   ۳) نمایش/اختفای بخش شرطی (دلیل عدم پذیرش، تنظیمات پیامک)
 *   ۴) حالت «در حال ارسال» برای دکمه‌ها (جلوگیری از ثبت دوباره)
 *   ۵) تأیید پیش از عملیات حساس (غیرفعال‌کردن کاربر)
 *   ۶) یادآوری نام کاربری (فقط نام کاربری، نه رمز)
 *   ۷) اصلاح خودکار ارقام فارسی/عربی به انگلیسی در موبایل و کد تأیید
 *   ۸) شمارش معکوس ارسال دوبارهٔ کد تأیید
 *   ۹) جست‌وجوی مراجع با شمارهٔ موبایل (AJAX سادهٔ XMLHttpRequest)
 * ═══════════════════════════════════════════════════════════════════ */

(function () {
  'use strict';

  /* ابزارهای کوتاه */
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $$(sel, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  }

  /* تبدیل ارقام فارسی و عربی به انگلیسی */
  function toEnglishDigits(str) {
    if (!str) { return ''; }
    var fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    var ar = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    for (var i = 0; i < 10; i++) {
      str = str.split(fa[i]).join(String(i)).split(ar[i]).join(String(i));
    }
    return str;
  }

  /* ── ۱) منوی موبایل ───────────────────────────────────────────────── */
  function initMenu() {
    var toggle = $('.menu-toggle');
    var sidebar = $('.sidebar');
    if (!toggle || !sidebar) { return; }
    toggle.setAttribute('aria-expanded', 'false');
    toggle.addEventListener('click', function () {
      var open = sidebar.classList.toggle('open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  /* ── ۲) نشانگر قدرت رمز (نمایشی؛ قانون ثبت در سمت سرور است) ──────── */
  function scorePassword(pwd) {
    var score = 0;
    if (pwd.length >= 8) { score++; }
    if (pwd.length >= 12) { score++; }
    if (/[a-z]/.test(pwd) && /[A-Z]/.test(pwd)) { score++; }
    if (/[0-9]/.test(pwd)) { score++; }
    if (/[^a-zA-Z0-9]/.test(pwd)) { score++; }
    return Math.min(score, 5);
  }

  function initStrengthMeters() {
    $$('[data-strength-for]').forEach(function (meter) {
      var input = document.getElementById(meter.getAttribute('data-strength-for'));
      if (!input) { return; }

      var bar = document.createElement('span');
      bar.className = 'strength-bar';
      var segs = [];
      for (var i = 0; i < 5; i++) {
        var s = document.createElement('span');
        s.className = 'strength-seg';
        bar.appendChild(s);
        segs.push(s);
      }
      var label = document.createElement('span');
      label.className = 'strength-label text-muted';
      label.textContent = '—';

      meter.classList.add('strength');
      meter.appendChild(bar);
      meter.appendChild(label);
      meter.setAttribute('aria-live', 'polite');

      input.addEventListener('input', function () {
        var val = input.value;
        var score = scorePassword(val);
        var cls = score <= 2 ? 'on-weak' : (score <= 3 ? 'on-medium' : 'on-strong');
        segs.forEach(function (seg, idx) {
          seg.className = 'strength-seg' + (idx < score ? ' ' + cls : '');
        });
        if (!val) {
          label.textContent = '—';
          label.className = 'strength-label text-muted';
        } else if (score <= 2) {
          label.textContent = 'ضعیف';
          label.className = 'strength-label text-danger';
        } else if (score <= 3) {
          label.textContent = 'متوسط';
          label.className = 'strength-label';
          label.style.color = '#F39C12';
        } else {
          label.textContent = 'قوی';
          label.className = 'strength-label text-success';
          label.style.color = '';
        }
      });
    });
  }

  /* ── ۳) بخش‌های شرطی ──────────────────────────────────────────────── */
  /* کاربرد: <input type="radio" data-toggle-target="#declineBox" data-toggle-when="checked"> */
  function applyToggle(el) {
    var target = $(el.getAttribute('data-toggle-target'));
    if (!target) { return; }
    var show;
    if (el.type === 'radio' || el.type === 'checkbox') {
      show = el.checked;
    } else {
      show = el.value === el.getAttribute('data-toggle-value');
    }
    target.classList.toggle('hidden', !show);
  }

  function initToggles() {
    var controls = $$('[data-toggle-target]');
    controls.forEach(function (el) {
      var handler = function () {
        /* رادیوهای هم‌نام هم باید به‌روز شوند */
        if (el.type === 'radio' && el.name) {
          $$('input[name="' + el.name + '"][data-toggle-target]').forEach(applyToggle);
        } else {
          applyToggle(el);
        }
      };
      el.addEventListener('change', handler);
      el.addEventListener('input', handler);
      applyToggle(el);
    });
  }

  /* ── ۴) حالت «در حال ارسال» ──────────────────────────────────────── */
  function initSubmitGuard() {
    $$('form[data-guard]').forEach(function (form) {
      form.addEventListener('submit', function () {
        var btn = form.querySelector('button[type="submit"], input[type="submit"]');
        if (!btn || btn.dataset.busy === '1') { return; }
        btn.dataset.busy = '1';
        var txt = btn.textContent;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner"></span> در حال پردازش…';
        /* اگر ارسال ناموفق ماند (مثلاً خطای اعتبارسنجی مرورگر) دکمه آزاد شود */
        window.setTimeout(function () {
          btn.disabled = false;
          btn.textContent = txt;
          btn.dataset.busy = '';
        }, 12000);
      });
    });
  }

  /* ── ۵) تأیید عملیات حساس ────────────────────────────────────────── */
  function initConfirm() {
    $$('[data-confirm]').forEach(function (el) {
      el.addEventListener('click', function (ev) {
        if (!window.confirm(el.getAttribute('data-confirm'))) {
          ev.preventDefault();
        }
      });
    });
  }

  /* ── ۶) یادآوری نام کاربری ───────────────────────────────────────── */
  function initRememberUsername() {
    var form = $('form[data-remember-username]');
    if (!form) { return; }
    var input = form.querySelector('input[name="login_identifier"]');
    var box = form.querySelector('input[name="remember_username"]');
    if (!input || !box) { return; }

    try {
      var saved = window.localStorage.getItem('joma_username');
      if (saved) {
        input.value = saved;
        box.checked = true;
      }
    } catch (e) { /* حالت مرور ناشناس */ }

    form.addEventListener('submit', function () {
      try {
        if (box.checked) {
          window.localStorage.setItem('joma_username', input.value);
        } else {
          window.localStorage.removeItem('joma_username');
        }
      } catch (e) { /* نادیده */ }
    });
  }

  /* ── ۷) اصلاح ارقام ──────────────────────────────────────────────── */
  function initDigitNormalizer() {
    $$('[data-digits="en"]').forEach(function (input) {
      var fix = function () {
        var v = toEnglishDigits(input.value).replace(/[^0-9]/g, '');
        if (v !== input.value) { input.value = v; }
      };
      input.addEventListener('input', fix);
      input.addEventListener('blur', fix);
      if (input.form) { input.form.addEventListener('submit', fix); }
    });
  }

  /* ── ۸) شمارش معکوس ارسال دوبارهٔ کد ─────────────────────────────── */
  function initCountdown() {
    $$('[data-countdown]').forEach(function (el) {
      var remaining = parseInt(el.getAttribute('data-countdown'), 10);
      var linkSel = el.getAttribute('data-countdown-enable');
      var link = linkSel ? $(linkSel) : null;
      if (isNaN(remaining)) { return; }
      if (link) { link.classList.add('disabled'); }

      var tick = function () {
        if (remaining <= 0) {
          el.textContent = 'اکنون می‌توانید دوباره درخواست کد بدهید.';
          if (link) { link.classList.remove('disabled'); }
          return;
        }
        var m = Math.floor(remaining / 60);
        var s = remaining % 60;
        el.textContent = 'ارسال دوبارهٔ کد تا ' +
          (m > 0 ? m + ' دقیقه و ' : '') + s + ' ثانیهٔ دیگر امکان‌پذیر است.';
        remaining--;
        window.setTimeout(tick, 1000);
      };
      tick();
    });
  }

  /* ── ۹) جست‌وجوی مراجع با موبایل (AJAX ساده) ─────────────────────── */
  /*  نشانه‌گذاری مورد انتظار در صفحهٔ ثبت پذیرش:
   *    #btnLookup            دکمهٔ جست‌وجو
   *    #mobile_number        ورودی موبایل
   *    #lookupResult         جعبهٔ پیام نتیجه
   *    #personFields         بخش نام و نام خانوادگی
   *  نشانی سرویس در ویژگی data-endpoint دکمه می‌آید.
   *  اگر جاوااسکریپت کار نکند، همان فرم به‌صورت معمولی POST می‌شود.    */
  function initPersonLookup() {
    var btn = $('#btnLookup');
    if (!btn) { return; }
    var endpoint = btn.getAttribute('data-endpoint');
    if (!endpoint) { return; }

    btn.addEventListener('click', function () {
      var mobileInput = $('#mobile_number');
      var box = $('#lookupResult');
      var fields = $('#personFields');
      if (!mobileInput || !box) { return; }

      var mobile = toEnglishDigits(mobileInput.value).replace(/[^0-9]/g, '');
      mobileInput.value = mobile;

      if (!/^09\d{9}$/.test(mobile)) {
        box.className = 'alert alert-error';
        box.textContent = '✗ شمارهٔ موبایل باید ۱۱ رقم و با 09 شروع شود.';
        box.classList.remove('hidden');
        mobileInput.focus();
        return;
      }

      box.className = 'alert alert-info';
      box.innerHTML = '<span class="spinner"></span> در حال جست‌وجو…';
      box.classList.remove('hidden');

      var xhr = new XMLHttpRequest();
      xhr.open('POST', endpoint, true);
      xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      xhr.timeout = 15000;

      xhr.onload = function () {
        var data = null;
        try { data = JSON.parse(xhr.responseText); } catch (e) { data = null; }

        if (!data || xhr.status !== 200) {
          box.className = 'alert alert-error';
          box.textContent = '✗ ارتباط با سرور برقرار نشد. لطفاً دوباره تلاش کنید یا فرم را به‌صورت معمولی ثبت نمایید.';
          return;
        }

        if (data.ok && data.found) {
          box.className = 'alert alert-success';
          box.textContent = '✓ این شماره قبلاً ثبت شده است: ' + data.full_name +
            ' — اطلاعات در فرم قرار گرفت.';
          var fn = $('#first_name');
          var ln = $('#last_name');
          if (fn) { fn.value = data.first_name; fn.readOnly = true; }
          if (ln) { ln.value = data.last_name; ln.readOnly = true; }
        } else if (data.ok) {
          box.className = 'alert alert-info';
          box.textContent = 'ℹ️ مراجع جدید است. لطفاً نام و نام خانوادگی را وارد کنید.';
          var fn2 = $('#first_name');
          var ln2 = $('#last_name');
          if (fn2) { fn2.readOnly = false; fn2.value = ''; fn2.focus(); }
          if (ln2) { ln2.readOnly = false; ln2.value = ''; }
        } else {
          box.className = 'alert alert-error';
          box.textContent = '✗ ' + (data.message || 'خطایی رخ داده است.');
        }
        if (fields) { fields.classList.remove('hidden'); }
      };

      xhr.onerror = xhr.ontimeout = function () {
        box.className = 'alert alert-error';
        box.textContent = '✗ ارتباط با سرور برقرار نشد. لطفاً دوباره تلاش کنید.';
      };

      var token = $('input[name="csrf_token"]');
      xhr.send('mobile_number=' + encodeURIComponent(mobile) +
        (token ? '&csrf_token=' + encodeURIComponent(token.value) : ''));
    });
  }


  /* ── فاز ۲: شمارش معکوس قفل موقت رزرو ──────────────────────────────
     فقط نمایش است؛ اعتبار واقعی قفل را سرور تعیین می‌کند. */
  function initHoldTimer() {
    var box = $('.hold-timer');
    if (!box) { return; }
    var left = parseInt(box.getAttribute('data-hold-seconds'), 10);
    if (isNaN(left)) { return; }
    var out = box.querySelector('.hold-countdown');
    if (!out) { return; }

    function fa(n) {
      return String(n).replace(/[0-9]/g, function (d) {
        return '۰۱۲۳۴۵۶۷۸۹'.charAt(parseInt(d, 10));
      });
    }

    function tick() {
      if (left <= 0) {
        out.textContent = 'به پایان رسید';
        out.className = 'hold-countdown hold-expired';
        var buttons = document.querySelectorAll('.hold-card button[type="submit"]');
        for (var i = 0; i < buttons.length; i++) {
          if (buttons[i].className.indexOf('btn-secondary') === -1) {
            buttons[i].disabled = true;
          }
        }
        return;
      }
      var m = Math.floor(left / 60);
      var s = left % 60;
      out.textContent = fa(m) + ':' + fa(s < 10 ? '0' + s : s);
      left--;
      window.setTimeout(tick, 1000);
    }
    tick();
  }

  /* ── راه‌اندازی ───────────────────────────────────────────────────── */
  function boot() {
    initMenu();
    initStrengthMeters();
    initToggles();
    initSubmitGuard();
    initConfirm();
    initRememberUsername();
    initDigitNormalizer();
    initCountdown();
    initPersonLookup();
    initHoldTimer();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();

/* ═══════════════════════════════════════════════════════════════════
   فاز ۳ — شمارندهٔ زندهٔ نویسه برای یادداشت محرمانه
   بدون AJAX و بدون ذخیرهٔ خودکار؛ فقط نمایش.
   اعتبارسنجی واقعی همیشه در سمت سرور انجام می‌شود.
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var FA = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

  function toPersianDigits(n) {
    return String(n).replace(/[0-9]/g, function (d) { return FA[+d]; });
  }

  function countChars(value) {
    /* شمارش بر مبنای نقطه‌کد، هماهنگ با mb_strlen در PHP */
    if (typeof Array.from === 'function') {
      return Array.from(value).length;
    }
    return value.length;
  }

  function wire(textarea) {
    var targetId = textarea.getAttribute('data-counter');
    var max = parseInt(textarea.getAttribute('data-maxchars'), 10);
    var out = targetId ? document.getElementById(targetId) : null;
    if (!out || !max) { return; }

    function update() {
      var n = countChars(textarea.value);
      out.textContent = toPersianDigits(n);
      out.classList.remove('is-warning', 'is-over');
      if (n > max) {
        out.classList.add('is-over');
      } else if (n > max * 0.9) {
        out.classList.add('is-warning');
      }
    }

    textarea.addEventListener('input', update);
    update();
  }

  function boot() {
    var list = document.querySelectorAll('textarea[data-counter]');
    for (var i = 0; i < list.length; i++) {
      wire(list[i]);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
