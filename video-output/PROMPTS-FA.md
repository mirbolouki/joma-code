# راهنمای بازتولید ویدیو با چهرهٔ شما

## چرا روش قبلی خراب شد؟
روش من **چسباندن عکس روی ویدیو** بود (مثل استیکر). ابزار درست **تولید دوبارهٔ ویدیو با هوش مصنوعی** است.

---

## مرحله ۱ — ابزار را انتخاب کن

| ابزار | آدرس | چرا؟ |
|---|---|---|
| **Google Veo 3** | `flow.google` | بهترین کیفیت سینمایی |
| **Kling AI** | `klingai.com` | ساده برای تازه‌کار، کیفیت عالی — **پیشنهاد من** |
| **Runway** | `runwayml.com` | قابلیت References برای حفظ چهره |

---

## مرحله ۲ — توضیح ثابت شخصیت‌ها

این‌ها را هر بار اول پرامپت‌ات بگذار تا چهره ثابت بماند.

### مرد (شخصیت اصلی — شما)
```
A man in his early 30s with olive-toned skin, dark brown eyes, thick dark
eyebrows, short dark stubble beard and a defined jawline. He wears an
oatmeal-beige ribbed knit beanie and a royal blue winter parka with a
bright orange zipper and orange trim, with a dark hood underneath.
```

### زن
```
A woman in her late 20s with long dark brown wavy hair and fair skin,
wearing a long cream off-white wool coat. Gentle facial features.
```

### محیط مشترک
```
Heavy winter snowfall, cinematic shallow depth of field, anamorphic lens,
natural skin texture, film grain, 35mm colour science.
```

---

## مرحله ۳ — ۱۶ پرامپت، نما به نما

### نما ۱ — چهرهٔ گریان (0.0 تا 1.7 ثانیه)
```
[مرد] + [محیط]
Extreme close-up, selfie angle, he holds the camera with one hand and
cries directly into the lens, tears rolling down his cheek, expression raw
and vulnerable. A frosted window with falling snow behind him. Cold blue
winter light. Slow subtle push-in, handheld.
```

### نما ۲ — آشپزخانه (1.7 تا 3.2)
```
[مرد، فقط شانهٔ آبی در پیش‌زمینه] + [زن] + [محیط]
Wide shot inside a rustic wooden kitchen. The [woman] stands near a white
fridge, warm tungsten lighting, wooden cabinets, kettle on the stove.
Over-the-shoulder from behind the blue shoulder. Locked-off camera.
```

### نما ۳ — گریهٔ دوباره (3.2 تا 4.6)
```
[مرد] + [محیط]
Extreme close-up, he raises the camera slightly, eyes red and wet, tears
spilling, lips trembling. Cold blue light, frosted window behind. Handheld.
```

### نما ۴ — نزدیک شدن زن (4.6 تا 5.8)
```
[زن] + [مرد، شانه در پیش‌زمینه] + [محیط]
Medium shot in the wooden kitchen. The [woman] walks slowly toward camera,
her smile fading. Lighting drops to near darkness, faint warm glow on her
face. Camera pans slightly right.
```

### نما ۵ — چهرهٔ شیطانی (5.8 تا 7.1)
```
[زن] + [محیط]
Horror extreme close-up. Her face has transformed into a demon: bloodless
ash-grey skin, completely black eyes with no whites, long stringy black
hair, bared sharp teeth in a menacing grin, veins on her forehead. She
stares into the lens and leans in. Desaturated cold lighting, heavy
vignette. Slow tilt down.
```
> اگر ابزار این صحنه را رد کرد: به‌جای `demon` بنویس `eerie unsettling face, pale grey skin, black eyes`

### نما ۶ — تمام‌قد (7.1 تا 8.2)
```
[مرد] + [محیط]
Medium full-body shot. The man stands in a bare stone-walled room with a
large window behind him, arms slightly away from his sides, snow outside,
cold daylight. Camera tilts up slightly. Locked-off.
```

### نما ۷ — حمله (8.2 تا 9.0)
```
[زن] + [مرد، شانه در پیش‌زمینه] + [محیط]
Fast handheld shot in the kitchen, the demon woman lunges violently toward
camera, hair whipping forward, motion blur. Over-the-shoulder past the blue
shoulder. Warm-to-cold light shift.
```

### نما ۸ — چرخش و بلند کردن (9.0 تا 9.5)
```
[مرد] + [زن] + [محیط]
In the stone-walled room, the man lifts the woman off the ground in a
dance-like spin, her cream coat flaring outward. Fast, energetic, slight
motion blur. Locked-off camera.
```

### نما ۹ — دریاچهٔ یخ‌بسته (9.5 تا 11.6)
```
[مرد] + [زن] + [محیط]
Wide cinematic shot on a vast frozen lake at golden-hour sunset. The couple
lie on the snow side by side, silhouetted against a blazing low sun.
Anamorphic lens flare, extremely long shadows. Slow pan left. Warm amber
and gold colour grade.
```

### نما ۱۰ — نزدیک‌تر (11.6 تا 12.8)
```
[مرد] + [زن] + [محیط]
Closer low-angle shot of the same scene. The couple still lie on the ice,
the sun directly behind them, huge circular lens flare blooms across the
frame. Golden hour rim light. Locked-off.
```

### نما ۱۱ — چهرهٔ زن (12.8 تا 14.9)
```
[زن] + [مرد، فقط دست] + [محیط]
Intimate close-up. The woman lies on the snow with eyes closed, smiling
peacefully, snowflakes caught in her dark hair. The man's hand in a blue
sleeve gently cradles the side of her head. Warm golden backlight. Locked-off.
```

### نما ۱۲ — چهرهٔ شما (14.9 تا 16.3) — مهم‌ترین نما
```
[مرد] + [محیط]
Close-up. The man lies on the snow with eyes closed, a peaceful serene
expression, frost crystals on his dark stubble and eyebrows, warm golden
sunlight washing over his face from the right. Snow falls softly. Locked-off.
```

### نما ۱۳ — دویدن (16.3 تا 18.4)
```
[مرد] + [زن] + [محیط]
Wide shot, rear three-quarter view. The couple run hand in hand away from
camera across the frozen lake at sunset, shadows stretching very long
toward camera. Breath vapour in cold air. Warm amber grade. Locked-off.
```

### نما ۱۴ — درخت تنها (18.4 تا 19.7)
```
[مرد] + [زن] + [محیط]
Extreme wide shot. A single bare tree on a snowy hill against a golden
sunset. The couple are tiny figures near it, casting one enormous shadow
toward camera. Minimal, serene, painterly. Locked-off.
```

### نما ۱۵ — کنار درخت (19.7 تا 21.2)
```
[مرد] + [زن] + [محیط]
Medium close-up near the tree. The man in profile with eyes closed, the
woman's dark hair in the foreground. Strong golden rim light from behind
the sunset. Shallow depth of field. Slow pan right.
```

### نما ۱۶ — پایان (21.2 تا 25.3)
```
[مرد] + [زن] + [محیط]
Intimate close-up, the couple press their foreheads together, both eyes
closed, silhouetted against a warm golden sunset glow. Soft focus, dreamy
and romantic. Slowly fades to black.
```

---

## مرحله ۴ — چطور چهره‌ات ثابت بماند؟

### راه ۱ (بهترین) — Image-to-Video
1. یک عکس ثابت از شخصیت با چهرهٔ خودت بساز
2. آن را در Kling/Runway به‌عنوان Starting Image بده
3. پرامپت بالا را بنویس

### راه ۲ — Face Reference
در Kling گزینهٔ **Face Reference** و در Runway گزینهٔ **References** را فعال کن و عکس خودت را آپلود کن.

---

## نکات مهم

- واترمارک `@KD_DIGIARTAI` مال سازندهٔ اصلی است — کپی نکن، برند خودت را بزن.
- خروجی دقیقاً فریم‌به‌فریم یکسان نمی‌شود، ولی با این پرامپت‌ها خیلی نزدیک می‌شود.

---

## ترتیب کار

| قدم | کار |
|---|---|
| ۱ | ثبت‌نام در `klingai.com` |
| ۲ | نمای ۱۲ را اول بساز (مهم‌ترین نما) |
| ۳ | اگر راضی بودی، بقیهٔ ۱۵ نما |
| ۴ | چیدن همه در یک ویدیو + صدا — **من این کار را انجام می‌دهم** |
