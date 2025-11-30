
import mysql from "mysql2/promise";

export async function db() {
  return mysql.createPool({
    host: "localhost",
    user: "u994733455_rogerthat",
    password: "staggerMYnagger987!",
    database: "u994733455_thehub",
    connectionLimit: 10
  });
}
