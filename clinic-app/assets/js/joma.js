(function () {
  var more = document.querySelector('[data-more-toggle]');
  var panel = document.querySelector('.mob-more');
  if (more && panel) {
    more.addEventListener('click', function () {
      panel.hidden = !panel.hidden;
    });
  }

  var user = document.querySelector('[data-live-username]');
  if (user) {
    user.addEventListener('input', function () {
      var box = document.getElementById('user-hints');
      if (!box) return;
      var v = user.value.trim();
      var hints = [
        { ok: v.length >= 3, t: 'حداقل ۳ نویسه' },
        { ok: v.length <= 20, t: 'حداکثر ۲۰ نویسه' },
        { ok: /^[a-zA-Z]/.test(v), t: 'با حرف انگلیسی شروع شود' },
        { ok: /^[a-zA-Z0-9._]*$/.test(v), t: 'فقط حروف، عدد، نقطه و زیرخط' }
      ];
      box.innerHTML = hints.map(function (h) {
        return '<div class="' + (h.ok ? 'ok' : 'bad') + '">' + (h.ok ? '✓ ' : '✗ ') + h.t + '</div>';
      }).join('');
      if (v.length >= 3) {
        fetch('api/username.php?u=' + encodeURIComponent(v)).then(function (r) { return r.json(); }).then(function (d) {
          box.innerHTML += '<div class="' + (d.ok ? 'ok' : 'bad') + '">' + (d.ok ? '✓ قابل استفاده است' : '✗ قبلاً استفاده شده') + '</div>';
        }).catch(function () {});
      }
    });
  }

  var phone = document.querySelector('[data-live-phone]');
  if (phone) {
    phone.addEventListener('input', function () {
      var box = document.getElementById('phone-hints');
      if (!box) return;
      var ok = /^09[0-9]{9}$/.test(phone.value.trim());
      box.innerHTML = phone.value
        ? '<div class="' + (ok ? 'ok' : 'bad') + '">' + (ok ? '✓ شماره معتبر است' : '✗ قالب صحیح: 09xxxxxxxxx') + '</div>'
        : '';
    });
  }

  var email = document.querySelector('[data-live-email]');
  if (email) {
    email.addEventListener('input', function () {
      var box = document.getElementById('email-hints');
      if (!box) return;
      var ok = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim());
      box.innerHTML = email.value
        ? '<div class="' + (ok ? 'ok' : 'bad') + '">' + (ok ? '✓ ایمیل معتبر است' : '✗ ایمیل معتبر نیست') + '</div>'
        : '';
    });
  }

  var p = document.querySelector('[name=password]');
  var c = document.querySelector('[name=confirm]');
  function pass() {
    var box = document.getElementById('pass-hints');
    if (!box || !p) return;
    var okLen = p.value.length >= 6;
    var okSame = !c || !c.value || c.value === p.value;
    box.innerHTML = '<div class="' + (okLen ? 'ok' : 'bad') + '">' + (okLen ? '✓' : '✗') + ' حداقل ۶ نویسه</div>' +
      '<div class="' + (okSame ? 'ok' : 'bad') + '">' + (okSame ? '✓' : '✗') + ' تکرار رمز یکسان است</div>';
  }
  if (p) p.addEventListener('input', pass);
  if (c) c.addEventListener('input', pass);

  document.querySelectorAll('.moodbtn').forEach(function (b) {
    b.addEventListener('click', function () {
      var n = b.getAttribute('data-name');
      var v = b.getAttribute('data-v');
      document.querySelectorAll('input[name="' + n + '"]').forEach(function (i) {
        i.checked = i.value === v;
      });
      b.parentNode.parentNode.querySelectorAll('.moodbtn').forEach(function (x) { x.classList.remove('on'); });
      b.classList.add('on');
    });
  });
})();
