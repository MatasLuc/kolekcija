# 🪙 e-Kolekcija.lt

Moderni platforma kolekcionieriams, skirta monetų, banknotų ir kitų vertybių paieškai bei bendruomenės naujienoms. Projektas sukurtas naudojant **PHP** ir **MySQL**, integruojant automatinį duomenų surinkimą (scraper) bei turinio valdymo sistemą.

## 🌟 Pagrindinės Funkcijos

### 🛒 El. Parduotuvė ir Katalogas
* **Automatinis Scraperis:** Sistema periodiškai nuskaito prekes iš partnerių svetainių (`pirkis.lt`) ir atnaujina katalogą realiu laiku.
* **Išmanus Kategorizavimas:** Algoritmas automatiškai atpažįsta prekę (pvz., "Numizmatika", "Bonistika") ir priskiria atitinkamai kategorijai.
* **Šalių Detekcija:** Pagal prekės pavadinimą automatiškai nustatoma kilmės šalis (pvz., "Lietuva 5 Litai" -> "Lietuva").
* **Filtravimas ir Paieška:** Vartotojai gali filtruoti prekes pagal kategoriją, šalį arba ieškoti pagal pavadinimą (su simbolių normalizavimu, pašalinant nereikalingus ženklus kaip `_` ar `...`).

### 📰 Naujienų Sistema
* **Dinaminis turinys:** Administratoriai gali kurti straipsnius su formatuotu tekstu.
* **Galerija:** Prie kiekvienos naujienos galima prisegti neribotą kiekį nuotraukų, nustatyti pagrindinį viršelį ir aprašymus.
* **Paprasta peržiūra:** Vartotojams pateikiamas švarus straipsnių sąrašas su santraukomis.

### ⚙️ Administratoriaus Pultas
* **Hero Valdymas:** Galimybė tiesiogiai keisti pagrindinio puslapio (Homepage) antraštę, tekstą, mygtukus.
* **Medijos Nustatymai:** Fone galima naudoti **Nuotrauką**, **Video** arba **Spalvą**.
* **Vartotojų Valdymas:** Galimybė suteikti arba atimti administratoriaus teises registruotiems nariams.
* **Saugos įrankiai:** Integruotas CSRF apsaugos mechanizmas visose formose.
* **Scraperio Valdymas:** Rankinis scraperio paleidimas, būsenos stebėjimas (Running/Finished), istorijos peržiūra ir duomenų valymas.

### 🔒 Saugumas ir Autentifikacija
* **Registracija/Prisijungimas:** Saugi vartotojų sesijų sistema.
* **Slaptažodžiai:** Visi slaptažodžiai hash'uojami (`password_hash`).
* **Apsaugos:** CSRF tokenai formose, SQL injekcijų prevencija (PDO Prepared Statements), XSS apsauga (`htmlspecialchars`).

---

## 📂 Projekto Struktūra

* `admin.php` – Pagrindinis valdymo centras (prekės, naujienos, vartotojai, dizainas).
* `scraper.php` – Logika duomenų rinkimui (su *User-Agent* rotacija ir *Retry* logika).
* `shop.php` – Prekių katalogas su filtrais ir paieška.
* `news.php` / `article.php` – Naujienų sąrašas ir individualaus straipsnio peržiūra.
* `db.php` – Duomenų bazės ryšys ir automatinė migracija.
* `functions.php` – Pagalbinės funkcijos (saugumas, šalių atpažinimas, vartotojų sesijos).
* `partials.php` – Pasikartojantys HTML elementai (header, footer, nav).
* `styles.css` – Minimalistinis, "švarus" dizainas.
* `uploads/` – Aplankas vartotojų įkeltoms nuotraukoms.

---

## 💡 Ką galima patobulinti (To-Do / Roadmap)

Šis projektas veikia puikiai kaip prototipas, tačiau norint jį paversti didelio masto (Enterprise) sistema, rekomenduojami šie patobulinimai:

### 1. Architektūra (MVC)
Šiuo metu logika ir vaizdas (HTML) yra sumaišyti vienuose failuose.
* **Pasiūlymas:** Atskirti logiką į *Controllers*, duomenis į *Models*, o vaizdą į *Views* (arba naudoti šablonų variklį kaip *Twig*). Tai palengvintų kodo skaitymą ir palaikymą.

### 2. Scraperio Optimizacija
Dabar scraperis veikia sinchroniškai arba per ilgą ciklą, kurį gali nutraukti serverio laiko limitai.
* **Pasiūlymas:** Naudoti **eilių sistemą** (pvz., RabbitMQ arba paprastą DB lentelę `jobs`). PHP skriptas tik įdėtų užduotį į eilę, o atskiras foninis procesas ("Worker") ją vykdytų.

### 3. Paveikslėlių Optimizavimas
Įkeliamos naujienų nuotraukos saugomos originaliu dydžiu.
* **Pasiūlymas:** Įkėlimo metu automatiškai sumažinti nuotraukas ir konvertuoti į **WebP** formatą, kad svetainė krautųsi greičiau.

### 4. Paieškos Greitis
Dabartinė paieška naudoja `LIKE %...%`, kas yra lėta esant dideliam prekių kiekiui.
* **Pasiūlymas:** Naudoti `FULLTEXT` indeksus MySQL arba integruoti "ElasticSearch" / "Meilisearch" greitai ir tiksliai paieškai.

### 5. Priklausomybių Valdymas
* **Pasiūlymas:** Įdiegti **Composer**. Tai leistų lengvai naudoti paruoštas bibliotekas (pvz., `Guzzle` užklausoms vietoje `curl` ar `Intervention Image` nuotraukų tvarkymui).

---
© 2025 e-Kolekcija.lt
