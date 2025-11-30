
import { db } from "@/lib/db";

export default async function RidersPage() {
  const pool = await db();
  const [rows]: any = await pool.query(
    "SELECT id, first_name, last_name, club FROM riders LIMIT 50"
  );

  return (
    <div style={{ padding: 20 }}>
      <h1>Riders (Next.js V4 test)</h1>
      <ul>
        {rows.map((r: any) => (
          <li key={r.id}>
            {r.first_name} {r.last_name} — {r.club}
          </li>
        ))}
      </ul>
    </div>
  );
}
