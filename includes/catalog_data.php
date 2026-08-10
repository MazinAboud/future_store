<?php
/**
 * تعريف كتالوج المتجر في مكان واحد.
 * يُستخدم لتوليد قائمة الصور المطلوبة ولإدخال المنتجات — فلا يفترق المصدران.
 *
 * كل صف: [brand_slug, name, slug, gsm_folder, gsm_slug, tier, [variants...]]
 * variant: [storage, color, price, stock, sku]
 *
 * ملاحظة صدق: المواصفات والأسعار **تقديرية توضيحية** لغرض العرض، وليست
 * بيانات رسمية من الشركات المصنّعة. عدّلها من لوحة الإدارة حسب سوقك.
 */

/** مواصفات عامة حسب الفئة — تُستخدم حين لا تتوفر تفاصيل مؤكدة للموديل. */
function tier_specs(string $tier): array {
    return match ($tier) {
        'flagship' => ['6.7 بوصة AMOLED 120Hz', 'معالج رائد ثماني النواة', '50 ميجابكسل رئيسية', '5000 مللي أمبير', 'IP68 ضد الماء والغبار'],
        'upper'    => ['6.6 بوصة AMOLED 120Hz', 'معالج علوي ثماني النواة', '50 ميجابكسل رئيسية', '4800 مللي أمبير', 'IP67 ضد الماء والغبار'],
        'mid'      => ['6.6 بوصة AMOLED 90Hz', 'معالج متوسط ثماني النواة', '50 ميجابكسل رئيسية', '5000 مللي أمبير', 'IP54 ضد الرذاذ'],
        'budget'   => ['6.5 بوصة IPS LCD 90Hz', 'معالج اقتصادي ثماني النواة', '50 ميجابكسل رئيسية', '5000 مللي أمبير', 'مقاومة رذاذ خفيفة'],
        'fold'     => ['شاشة قابلة للطي AMOLED 120Hz', 'معالج رائد ثماني النواة', '50 ميجابكسل رئيسية', '4400 مللي أمبير', 'IPX8 ضد الماء'],
        default    => ['6.5 بوصة AMOLED', 'معالج ثماني النواة', '50 ميجابكسل', '5000 مللي أمبير', 'مقاومة رذاذ'],
    };
}

function catalog(): array { return [
// ─────────────────────────── APPLE (18) ───────────────────────────
['apple','iPhone 12','iphone-12','apple','apple-iphone-12','upper',[['64GB','أسود',399,8,'IP12-64-BLK'],['128GB','أزرق',449,5,'IP12-128-BLU']]],
['apple','iPhone 12 Pro','iphone-12-pro','apple','apple-iphone-12-pro','flagship',[['128GB','جرافيت',549,4,'IP12P-128-GRA'],['256GB','فضي',629,3,'IP12P-256-SLV']]],
['apple','iPhone 12 Pro Max','iphone-12-pro-max','apple','apple-iphone-12-pro-max','flagship',[['256GB','أزرق باسيفيكي',699,3,'IP12PM-256-PAC']]],
['apple','iPhone 13','iphone-13','apple','apple-iphone-13','upper',[['128GB','منتصف الليل',499,9,'IP13-128-MID'],['256GB','وردي',579,5,'IP13-256-PNK']]],
['apple','iPhone 13 Pro','iphone-13-pro','apple','apple-iphone-13-pro','flagship',[['128GB','أزرق سييرا',649,5,'IP13P-128-SIE'],['256GB','جرافيت',729,3,'IP13P-256-GRA']]],
['apple','iPhone 13 Pro Max','iphone-13-pro-max','apple','apple-iphone-13-pro-max','flagship',[['256GB','ذهبي',799,3,'IP13PM-256-GLD']]],
['apple','iPhone 14','iphone-14','apple','apple-iphone-14','upper',[['128GB','أزرق',599,7,'IP14-128-BLU'],['256GB','بنفسجي',679,4,'IP14-256-PUR']]],
['apple','iPhone 14 Plus','iphone-14-plus','apple','apple-iphone-14-plus','upper',[['128GB','أصفر',669,5,'IP14PL-128-YEL']]],
['apple','iPhone 14 Pro','iphone-14-pro','apple','apple-iphone-14-pro','flagship',[['128GB','بنفسجي داكن',799,6,'IP14P-128-DPU'],['256GB','أسود فضائي',879,3,'IP14P-256-SBK']]],
['apple','iPhone 14 Pro Max','iphone-14-pro-max','apple','apple-iphone-14-pro-max','flagship',[['256GB','ذهبي',949,4,'IP14PM-256-GLD'],['512GB','فضي',1099,2,'IP14PM-512-SLV']]],
['apple','iPhone 15','iphone-15','apple','apple-iphone-15','upper',[['128GB','أسود',749,10,'IP15-128-BLK'],['256GB','أخضر',849,6,'IP15-256-GRN']]],
['apple','iPhone 15 Plus','iphone-15-plus','apple','apple-iphone-15-plus','upper',[['128GB','أزرق',849,5,'IP15PL-128-BLU']]],
['apple','iPhone 15 Pro','iphone-15-pro','apple','apple-iphone-15-pro','flagship',[['128GB','تيتانيوم طبيعي',999,7,'IP15P-128-NAT'],['256GB','تيتانيوم أزرق',1099,4,'IP15P-256-BLU']]],
['apple','iPhone 15 Pro Max','iphone-15-pro-max','apple','apple-iphone-15-pro-max','flagship',[['256GB','تيتانيوم أسود',1199,5,'IP15PM-256-BLK'],['512GB','تيتانيوم أبيض',1399,2,'IP15PM-512-WHT']]],
['apple','iPhone 16','iphone-16','apple','apple-iphone-16','upper',[['128GB','أزرق فاتح',849,11,'IP16-128-BLU'],['256GB','أسود',949,6,'IP16-256-BLK']]],
['apple','iPhone 16 Pro','iphone-16-pro','apple','apple-iphone-16-pro','flagship',[['256GB','تيتانيوم صحراوي',1149,6,'IP16P-256-DST'],['512GB','تيتانيوم أسود',1349,3,'IP16P-512-BLK']]],
['apple','iPhone 16 Pro Max','iphone-16-pro-max','apple','apple-iphone-16-pro-max','flagship',[['256GB','تيتانيوم طبيعي',1299,4,'IP16PM-256-NAT'],['512GB','تيتانيوم أسود',1499,2,'IP16PM-512-BLK']]],
['apple','iPhone 17 Pro Max','iphone-17-pro-max','apple','apple-iphone-17-pro-max','flagship',[['256GB','تيتانيوم أسود',1449,3,'IP17PM-256-BLK'],['512GB','تيتانيوم فضي',1649,2,'IP17PM-512-SLV']]],

// ─────────────────────────── SAMSUNG (16) ───────────────────────────
['samsung','Galaxy S22','galaxy-s22','samsung','samsung-galaxy-s22-5g','upper',[['128GB','أخضر',449,6,'SGS22-128-GRN']]],
['samsung','Galaxy S22 Plus','galaxy-s22-plus','samsung','samsung-galaxy-s22-plus-5g','flagship',[['256GB','أسود',549,4,'SGS22P-256-BLK']]],
['samsung','Galaxy S22 Ultra','galaxy-s22-ultra','samsung','samsung-galaxy-s22-ultra-5g','flagship',[['256GB','بورجندي',649,4,'SGS22U-256-BUR']]],
['samsung','Galaxy S23','galaxy-s23','samsung','samsung-galaxy-s23-5g','flagship',[['128GB','كريمي',599,7,'SGS23-128-CRM'],['256GB','أخضر',679,4,'SGS23-256-GRN']]],
['samsung','Galaxy S23 Plus','galaxy-s23-plus','samsung','samsung-galaxy-s23-plus-5g','flagship',[['256GB','أسود',779,4,'SGS23P-256-BLK']]],
['samsung','Galaxy S23 Ultra','galaxy-s23-ultra','samsung','samsung-galaxy-s23-ultra-5g','flagship',[['256GB','أخضر',899,5,'SGS23U-256-GRN'],['512GB','كريمي',1049,2,'SGS23U-512-CRM']]],
['samsung','Galaxy S24','galaxy-s24','samsung','samsung-galaxy-s24-5g-sm-s921','flagship',[['128GB','أسود أونيكس',749,9,'SGS24-128-ONX'],['256GB','أصفر',829,5,'SGS24-256-YEL']]],
['samsung','Galaxy S24 Plus','galaxy-s24-plus','samsung','samsung-galaxy-s24-plus-5g-sm-s926','flagship',[['256GB','رمادي كوبالت',949,4,'SGS24P-256-COB']]],
['samsung','Galaxy S24 Ultra','galaxy-s24-ultra','samsung','samsung-galaxy-s24-ultra-5g-sm-s928','flagship',[['256GB','تيتانيوم رمادي',1099,6,'SGS24U-256-GRY'],['512GB','تيتانيوم بنفسجي',1299,3,'SGS24U-512-VIO']]],
['samsung','Galaxy S21 FE','galaxy-s21-fe','samsung','samsung-galaxy-s21-fe-5g','upper',[['128GB','أبيض',379,7,'SGS21FE-128-WHT']]],
['samsung','Galaxy Z Flip6','galaxy-z-flip6','samsung','samsung-galaxy-z-flip6','fold',[['256GB','أزرق',1049,4,'SGZF6-256-BLU'],['512GB','فضي',1199,2,'SGZF6-512-SLV']]],
['samsung','Galaxy Z Fold6','galaxy-z-fold6','samsung','samsung-galaxy-z-fold6','fold',[['256GB','وردي',1699,2,'SGZFD6-256-PNK']]],
['samsung','Galaxy A55','galaxy-a55','samsung','samsung-galaxy-a55','mid',[['128GB','أزرق ثلجي',399,14,'SGA55-128-ICE'],['256GB','أسود',449,8,'SGA55-256-BLK']]],
['samsung','Galaxy A35','galaxy-a35','samsung','samsung-galaxy-a35','mid',[['128GB','ليلكي',319,15,'SGA35-128-LIL']]],
['samsung','Galaxy A25','galaxy-a25','samsung','samsung-galaxy-a25','mid',[['128GB','أزرق',259,16,'SGA25-128-BLU']]],
['samsung','Galaxy A05s','galaxy-a05s','samsung','samsung-galaxy-a05s','budget',[['128GB','أسود',149,20,'SGA05S-128-BLK']]],

// ─────────────────────────── XIAOMI (15) ───────────────────────────
['xiaomi','Xiaomi 15','xiaomi-15','xiaomi','xiaomi-15','flagship',[['256GB','أسود',849,5,'XM15-256-BLK'],['512GB','أخضر',949,3,'XM15-512-GRN']]],
['xiaomi','Xiaomi 15 Pro','xiaomi-15-pro','xiaomi','xiaomi-15-pro','flagship',[['512GB','أسود',1099,3,'XM15P-512-BLK']]],
['xiaomi','Xiaomi 14','xiaomi-14','xiaomi','xiaomi-14','flagship',[['256GB','أخضر',599,8,'XM14-256-GRN'],['512GB','أسود',679,4,'XM14-512-BLK']]],
['xiaomi','Xiaomi 14 Pro','xiaomi-14-pro','xiaomi','xiaomi-14-pro','flagship',[['256GB','أسود',749,6,'XM14P-256-BLK'],['512GB','أبيض',849,3,'XM14P-512-WHT']]],
['xiaomi','Xiaomi 14 Ultra','xiaomi-14-ultra','xiaomi','xiaomi-14-ultra','flagship',[['512GB','أسود',999,3,'XM14U-512-BLK']]],
['xiaomi','Xiaomi 13T','xiaomi-13t','xiaomi','xiaomi-13t','upper',[['256GB','أزرق',449,7,'XM13T-256-BLU']]],
['xiaomi','Xiaomi 13T Pro','xiaomi-13t-pro','xiaomi','xiaomi-13t-pro','flagship',[['512GB','أسود',599,4,'XM13TP-512-BLK']]],
['xiaomi','Redmi Note 13','redmi-note-13','xiaomi','xiaomi-redmi-note-13','mid',[['128GB','أزرق',199,18,'RN13-128-BLU'],['256GB','أسود',239,10,'RN13-256-BLK']]],
['xiaomi','Redmi Note 13 Pro','redmi-note-13-pro','xiaomi','xiaomi-redmi-note-13-pro','mid',[['256GB','بنفسجي',299,12,'RN13P-256-PUR']]],
['xiaomi','Redmi Note 13 Pro Plus','redmi-note-13-pro-plus','xiaomi','xiaomi-redmi-note-13-pro-plus','upper',[['256GB','أسود',379,8,'RN13PP-256-BLK']]],
['xiaomi','POCO X6','poco-x6','xiaomi','xiaomi-poco-x6','mid',[['256GB','أزرق',279,11,'PX6-256-BLU']]],
['xiaomi','POCO X6 Pro','poco-x6-pro','xiaomi','xiaomi-poco-x6-pro','upper',[['256GB','أصفر',349,9,'PX6P-256-YEL']]],
['xiaomi','POCO F6','poco-f6','xiaomi','xiaomi-poco-f6','upper',[['256GB','أسود',399,7,'PF6-256-BLK']]],
['xiaomi','POCO F6 Pro','poco-f6-pro','xiaomi','xiaomi-poco-f6-pro','flagship',[['512GB','أبيض',549,4,'PF6P-512-WHT']]],
['xiaomi','Redmi 13C','redmi-13c','xiaomi','xiaomi-redmi-13c','budget',[['128GB','أخضر',119,22,'R13C-128-GRN']]],

// ─────────────────────────── HUAWEI (15) ───────────────────────────
['huawei','Huawei P60 Pro','huawei-p60-pro','huawei','huawei-p60-pro','flagship',[['256GB','لؤلؤي',799,4,'HWP60P-256-PRL'],['512GB','أسود',899,2,'HWP60P-512-BLK']]],
['huawei','Huawei P60','huawei-p60','huawei','huawei-p60','flagship',[['256GB','أخضر',649,5,'HWP60-256-GRN']]],
['huawei','Huawei Mate 50 Pro','huawei-mate-50-pro','huawei','huawei-mate-50-pro','flagship',[['256GB','فضي',749,4,'HWM50P-256-SLV']]],
['huawei','Huawei Mate 50','huawei-mate-50','huawei','huawei-mate-50','flagship',[['256GB','أسود',629,4,'HWM50-256-BLK']]],
['huawei','Huawei P50 Pro','huawei-p50-pro','huawei','huawei-p50-pro','flagship',[['256GB','ذهبي',599,3,'HWP50P-256-GLD']]],
['huawei','Huawei P50','huawei-p50','huawei','huawei-p50','upper',[['128GB','أسود',479,5,'HWP50-128-BLK']]],
['huawei','Huawei Mate X3','huawei-mate-x3','huawei','huawei-mate-x3','fold',[['512GB','أسود',1799,2,'HWMX3-512-BLK']]],
['huawei','Huawei Mate X5','huawei-mate-x5','huawei','huawei-mate-x5','fold',[['512GB','ذهبي',1999,2,'HWMX5-512-GLD']]],
['huawei','Huawei nova 12','huawei-nova-12','huawei','huawei-nova-12','upper',[['256GB','أسود',399,8,'HWN12-256-BLK']]],
['huawei','Huawei nova 12 Pro','huawei-nova-12-pro','huawei','huawei-nova-12-pro','flagship',[['512GB','أبيض',549,5,'HWN12P-512-WHT']]],
['huawei','Huawei nova 12i','huawei-nova-12i','huawei','huawei-nova-12i','mid',[['128GB','أزرق',249,12,'HWN12I-128-BLU']]],
['huawei','Huawei nova 11i','huawei-nova-11i','huawei','huawei-nova-11i','mid',[['128GB','أسود',219,13,'HWN11I-128-BLK']]],
['huawei','Huawei nova 10','huawei-nova-10','huawei','huawei-nova-10','upper',[['128GB','فضي',329,9,'HWN10-128-SLV']]],
['huawei','Huawei nova 10 Pro','huawei-nova-10-pro','huawei','huawei-nova-10-pro','flagship',[['256GB','أخضر',449,6,'HWN10P-256-GRN']]],
['huawei','Huawei P30 Pro','huawei-p30-pro','huawei','huawei-p30-pro','upper',[['256GB','أزرق متدرج',299,6,'HWP30P-256-BLU']]],

// ─────────────────────────── HONOR (16) ───────────────────────────
['honor','Honor Magic7 Pro','honor-magic7-pro','honor','honor-magic7-pro','flagship',[['512GB','أسود',1049,3,'HNM7P-512-BLK']]],
['honor','Honor Magic6 Pro','honor-magic6-pro','honor','honor-magic6-pro','flagship',[['256GB','أخضر',849,5,'HNM6P-256-GRN'],['512GB','أسود',949,3,'HNM6P-512-BLK']]],
['honor','Honor Magic6 Lite','honor-magic6-lite','honor','honor-magic6-lite','mid',[['256GB','فضي',329,10,'HNM6L-256-SLV']]],
['honor','Honor Magic5 Pro','honor-magic5-pro','honor','honor-magic5-pro','flagship',[['256GB','أسود',649,4,'HNM5P-256-BLK']]],
['honor','Honor Magic5 Lite','honor-magic5-lite','honor','honor-magic5-lite','mid',[['128GB','أزرق',269,12,'HNM5L-128-BLU']]],
['honor','Honor Magic4 Pro','honor-magic4-pro','honor','honor-magic4-pro','flagship',[['256GB','أسود',499,4,'HNM4P-256-BLK']]],
['honor','Honor 400 Pro','honor-400-pro','honor','honor-400-pro','flagship',[['512GB','رمادي',699,4,'HN400P-512-GRY']]],
['honor','Honor 200 Pro','honor-200-pro','honor','honor-200-pro','flagship',[['256GB','أبيض',599,6,'HN200P-256-WHT']]],
['honor','Honor 200','honor-200','honor','honor-200','upper',[['256GB','أخضر',479,7,'HN200-256-GRN']]],
['honor','Honor 90','honor-90','honor','honor-90','upper',[['256GB','أخضر زمردي',399,8,'HN90-256-EMR']]],
['honor','Honor 90 Lite','honor-90-lite','honor','honor-90-lite','mid',[['256GB','أزرق',249,11,'HN90L-256-BLU']]],
['honor','Honor 70','honor-70','honor','honor-70','upper',[['128GB','أزرق',299,7,'HN70-128-BLU']]],
['honor','Honor X9b','honor-x9b','honor','honor-x9b','mid',[['256GB','أسود',269,12,'HNX9B-256-BLK']]],
['honor','Honor X9a','honor-x9a','honor','honor-x9a','mid',[['128GB','فضي',229,13,'HNX9A-128-SLV']]],
['honor','Honor X8b','honor-x8b','honor','honor-x8b','mid',[['256GB','ذهبي',209,14,'HNX8B-256-GLD']]],
['honor','Honor X7b','honor-x7b','honor','honor-x7b','budget',[['128GB','أخضر',159,18,'HNX7B-128-GRN']]],
]; }
