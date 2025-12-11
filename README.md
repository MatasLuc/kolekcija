# e-kolekcija demonstracinė svetainė

PHP + MySQL prototipas su registracija, prisijungimu, administratoriaus pultu ir naujienų valdymu.

## Paleidimas
1. Duomenų bazė ir lentelės sukuriamos automatiškai pirmo prisijungimo metu pagal `.env` reikšmes, tad papildomai leisti `SOURCE schema.sql` nereikia.
2. Nustatykite prisijungimus `.env` faile projekto šaknyje (galite kopijuoti iš `.env.example`; visos DB_* reikšmės yra privalomos, o prireikus galite nurodyti nestandartinį `DB_PORT` arba `DB_SOCKET`):
   ```bash
   cp .env.example .env
   # pakoreguokite DB_* reikšmes pagal savo MySQL
   ```
   Arba eksportuokite kintamuosius į aplinką:
   ```bash
   export DB_HOST=localhost
   export DB_NAME=kolekcija
   export DB_USER=root
   export DB_PASS="slaptazodis"
   export DB_PORT=3306
   ```
3. Paleiskite PHP serverį projekto aplanke:
   ```bash
   php -S localhost:8000
   ```
4. Registruokite naują vartotoją, tada administratoriaus rolę galite suteikti per "Vartotojų rolės" lentelę admin pulto lange.

## Pagrindinės funkcijos
- Juodai baltas dizainas su #f1f1f1 fonu ir animuotu hero vaizdu per visą plotį.
- Prisijungimas, registracija, atsijungimas.
- Administratoriaus pultas su hero tekstų, nuorodos, paveikslėlio ir naujienų kūrimu/redagavimu.
- Galimybė vartotoją paskirti administratoriumi.
- Naujienų sąrašas /news.php puslapyje.
