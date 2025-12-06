# Schedule Sozlash

## Server'da Cron Job Sozlash

Command har kuni avtomatik ishlashi uchun server'da cron job sozlash kerak.

### 1. Crontab'ni ochish:

```bash
crontab -e
```

### 2. Quyidagi qatorni qo'shing:

```bash
* * * * * cd /home/xurshid/Desktop/multi_bots && php artisan schedule:run >> /dev/null 2>&1
```

**Eslatma:** `/home/xurshid/Desktop/multi_bots` o'rniga loyihangizning to'liq yo'lini yozing.

### 3. Saqlash va chiqish

### 4. Tekshirish:

```bash
crontab -l
```

## Command Qo'lda Ishlatish

Agar schedule ishlamasa yoki test qilmoqchi bo'lsangiz:

```bash
php artisan groups:sync-members-count
```

## Schedule Vaqti

Command har kuni **ertalab soat 02:00** da (Toshkent vaqti bilan) avtomatik ishlaydi.

Vaqtni o'zgartirish uchun `bootstrap/app.php` faylida `->at('02:00')` ni o'zgartiring.
