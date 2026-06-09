# Backend for Prayer Sync (MySQL)

Android app loads prayers from endpoint:

- `GET /api/prayers`

Response format:

```json
[
  {
    "id": 1,
    "title": "Отче наш",
    "text": "Отче наш, сущий на небесах...",
    "category": "Основные",
    "language": "ru"
  }
]
```

## 1) MySQL table

```sql
CREATE TABLE prayers (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  title VARCHAR(255) NOT NULL,
  text TEXT NOT NULL,
  category VARCHAR(100) NULL,
  language VARCHAR(20) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

## 2) Example Node.js API

Use this if you want quick hosting with MySQL:

```js
import express from "express";
import mysql from "mysql2/promise";

const app = express();
const pool = mysql.createPool({
  host: process.env.DB_HOST,
  user: process.env.DB_USER,
  password: process.env.DB_PASSWORD,
  database: process.env.DB_NAME,
});

app.get("/api/prayers", async (_req, res) => {
  const [rows] = await pool.query(
    `SELECT id, title, text, category, language
     FROM prayers
     WHERE is_active = 1
     ORDER BY id DESC`
  );
  res.json(rows);
});

app.listen(3000, () => {
  console.log("API is running on port 3000");
});
```

## 3) Android configuration

In file `PrayerApiClient.kt` replace:

- `https://example.com/api/`

with your real API base URL, e.g.:

- `https://your-domain.com/api/`
