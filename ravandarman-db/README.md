# بانک اطلاعاتی روانشناسان و روانپزشکان — استخراج از ravandarman.com

اپلیکیشن وب فارسی (RTL) برای جستجو و دسته‌بندی متخصصان سلامت روان، با داده‌های استخراج‌شده از پلتفرم [روان‌درمان](https://ravandarman.com).

## ساختار پروژه

```
ravandarman-db/
├── data/              # داده‌های خام استخراج‌شده (PSV، جداکننده |)
│   ├── batch1.psv     # id|name|type|professions|city|area|rating|votes|visits
│   ├── batch1-slugs.txt
│   ├── batch2.psv     # id|slug|name|type|professions|city|area|rating|votes|visits
│   ├── batch3.psv
│   └── batch4.psv
├── build_db.py        # ادغام + خروجی doctors.json و doctors.csv
└── app/
    ├── index.html     # رابط کاربری (جستجو + فیلتر شهر/حوزه/نوع ویزیت/نوع تخصص + مرتب‌سازی)
    ├── app.js
    ├── doctors.json   # دیتابیس نهایی
    └── doctors.csv    # خروجی اکسل (UTF-8 BOM)
```

## اجرا

```bash
python3 build_db.py                       # بازسازی دیتابیس از فایل‌های data/
cd app && python3 -m http.server 8000     # اجرای اپ روی پورت 8000
```

## فیلدهای هر متخصص

| فیلد | توضیح |
|---|---|
| `id`, `url` | شناسه و لینک پروفایل رسمی در روان‌درمان |
| `name` | نام کامل (با پیشوند دکتر در صورت وجود) |
| `type` | «روانشناس» یا «روانپزشک» |
| `professions` | حوزه‌های فعالیت (زوج‌درمانگر، مشاور خانواده، روانشناس کودک، سکس‌تراپیست، روانکاو و…) |
| `city`, `area` | شهر و محدوده/آدرس مطب |
| `rating`, `votes` | امتیاز کاربران (از ۵) و تعداد نظرات |
| `visits` | انواع ویزیت: حضوری / تلفنی / آنلاین |

## دامنه و محدودیت‌های داده (فاز ۱)

- سایت مبدأ ~۴۴,۹۰۰ پروفایل دارد که تنها **~۶–۷ هزار پروفایل فعال** (دارای نوبت‌دهی) است.
- سایت مبدأ دسترسی مستقیم سرور را مسدود می‌کند و API عمومی ندارد؛ داده‌ها صفحه‌به‌صفحه از فهرست جستجو (مرتب‌شده بر اساس محبوبیت خود سایت) استخراج شده‌اند.
- فاز ۱ شامل **۱۱۶ متخصص برتر از ۲۲ شهر** است (صفحات ۱–۴ فهرست سراسری + روانپزشکان منتخب + متخصصان ویژهٔ صفحهٔ اصلی).
- برای گسترش: صفحات بعدی `https://ravandarman.com/search?page=N` (تا 1797) را با همان الگو به فایل‌های `data/batchN.psv` اضافه کنید و `build_db.py` را دوباره اجرا کنید.

## الگوی URLهای مفید سایت مبدأ

- فهرست سراسری: `/search?page=N` (هر صفحه ۲۵ متخصص)
- بر اساس شهر: `/psychologists/{cityId}c-{citySlug}` — مثل `1c-tehran`, `17c-mashhad`, `19c-esfahan`, `2c-shiraz`, `9c-karaj`, `13c-tabriz`, `11c-rasht`, `32c-ahvaz`, `55c-qom`, `56c-kermanshah`
- بر اساس حوزه: `/psychologists/{profId}p-{profSlug}` — مثل `104p-couple-therapist`, `62p-family-therapist`, `38p-child-psychologist`
- نقشهٔ سایت متخصصان فعال: `/sitemap/active-doctors.xml?p=1..2`
