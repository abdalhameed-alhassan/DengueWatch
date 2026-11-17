#!/bin/sh
# أداة محلية: يبني database/database.sql (هيكل + مراجع + بيانات تجريبية) من قاعدة مولّدة محلياً باسم dengue_gen. بلا DROP ولا CREATE DATABASE.
set -e
cd "$(dirname "$0")/.."
OUT=database/database.sql
{
  echo "-- DengueWatch SD — قاعدة بيانات النشر (هيكل + بيانات مرجعية + بيانات تجريبية مولّدة). بلا DROP ولا CREATE DATABASE."
  echo "-- كلمات مرور كل المستخدمين عشوائية غير معروفة؛ الدخول للمراجعة عبر زر الدخول التجريبي (DEMO_LOGIN=1)."
  echo "SET NAMES utf8mb4; SET FOREIGN_KEY_CHECKS = 0;"
  grep -v -E '^DROP TABLE|^SET FOREIGN_KEY_CHECKS|^SET NAMES' database/schema.sql
  grep -v -E '^SET FOREIGN_KEY_CHECKS|^SET NAMES' database/locations.sql
  mysqldump -uroot --no-create-info --complete-insert --skip-add-locks --skip-triggers --no-tablespaces --skip-comments --net-buffer-length=500000 \
    --hex-blob dengue_gen facilities users cases alerts forecast_runs forecasts
  echo "SET FOREIGN_KEY_CHECKS = 1;"
} > $OUT
ls -l $OUT
