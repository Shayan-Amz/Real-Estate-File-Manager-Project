"""Regression checks for the SELECTs used by getData (fix26).

Run: python3 -m unittest discover -s tests -p 'test_*.py' -v

Extracts the actual SQL from api.php and runs it on in-memory SQLite fixtures.
This is NOT an end-to-end PHP/MySQL test or a production load test. It needs no
credentials, does not contact the host, and is not part of the deployment ZIP.
API_UNDER_TEST can point to an older api.php to check that regressions are caught.
"""

import os
from pathlib import Path
import re
import sqlite3
import unittest


ROOT = Path(__file__).resolve().parents[1]
API = Path(os.environ.get("API_UNDER_TEST", ROOT / "src/api.php"))
PRIVATE_GATE = "if ($isFullyAuthenticated && $agencyId) {"


def get_sections(source):
    """Anchor on the real PHP role/auth gates, not copies of its SQL."""
    data = source.split("if ($action === 'getData') {", 1)[1]
    data = data.split("// POST METHODS", 1)[0]
    agencies = data.split(
        "if ($isFullyAuthenticated && $userRole === 'مدیر') {", 1
    )[1]
    manager, rest = agencies.split(
        "} elseif ($isFullyAuthenticated && $userRole === 'مشاور') {", 1
    )
    consultant, rest = rest.split("} else {", 1)
    guest_agencies, rest = rest.split(PRIVATE_GATE, 1)
    own_properties, rest = rest.split("} else {", 1)
    guest_properties, rest = rest.split("while($row = $stmt->fetch()) {", 1)
    private_lists = rest.split(PRIVATE_GATE, 1)[1].split("$out['dataHash']", 1)[0]
    return {
        "manager": manager,
        "consultant": consultant,
        "guest_agencies": guest_agencies,
        "own_properties": own_properties,
        "guest_properties": guest_properties,
        "private_lists": private_lists,
    }


def queries(section):
    return re.findall(r'\$pdo->(prepare|query)\("([^"\n]+)"\)', section)


class GetDataScopeTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.sections = get_sections(API.read_text(encoding="utf-8"))

    def setUp(self):
        self.db = sqlite3.connect(":memory:")
        self.addCleanup(self.db.close)
        self.db.row_factory = sqlite3.Row
        self.db.executescript("""
            CREATE TABLE agencies (
                id TEXT PRIMARY KEY, name TEXT, city TEXT, phone TEXT,
                phone2 TEXT, managerName TEXT, expireAt TEXT, createdAt TEXT,
                plan_type TEXT, adminPin TEXT, masterPass TEXT
            );
            CREATE TABLE properties (
                id TEXT PRIMARY KEY, agencyId TEXT, status TEXT,
                showToGuest INTEGER, phone TEXT, internalNote TEXT
            );
            CREATE INDEX idx_agencyId ON properties (agencyId);
            CREATE INDEX idx_status_guest ON properties (status, showToGuest);
            CREATE TABLE demands (id TEXT PRIMARY KEY, agencyId TEXT, clientName TEXT);
            CREATE TABLE members (
                id TEXT PRIMARY KEY, agencyId TEXT, name TEXT, role TEXT,
                status TEXT, joinedAt TEXT, lastSeen INTEGER, pin TEXT
            );
        """)
        for agency in ("100001", "100002", "100003"):
            self.db.execute(
                "INSERT INTO agencies VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                (agency, "دفتر " + agency, "تهران", "phone-fixture", "", "مدیر آزمایشی",
                 "2027-12-31", "2026-09-07", "vip", "fake-hash", "fake-hash"),
            )
        # Two populated agencies and one empty one; cover all visibility/status pairs.
        for agency in ("100001", "100002"):
            for suffix, status, public in (
                ("public", "موجود", 1), ("hidden", "موجود", 0),
                ("sold-public", "واگذار شده", 1), ("sold-hidden", "واگذار شده", 0),
            ):
                self.db.execute(
                    "INSERT INTO properties VALUES (?, ?, ?, ?, ?, ?)",
                    (agency + "_" + suffix, agency, status, public, "private-phone", "یادداشت"),
                )
            self.db.execute("INSERT INTO demands VALUES (?, ?, ?)", ("d_" + agency, agency, "متقاضی"))
            self.db.execute(
                "INSERT INTO members VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                ("m_" + agency, agency, "مشاور آزمایشی", "مشاور", "active", "2026-09-07", 100, "fake-pin"),
            )

    def one_query(self, section):
        found = queries(self.sections[section])
        self.assertEqual(len(found), 1, "Unexpected query count in " + section)
        return found[0]

    def rows(self, section, agency=None):
        mode, sql = self.one_query(section)
        if agency is None:
            self.assertEqual(mode, "query")
            self.assertNotIn("?", sql)
            params = ()
        else:
            self.assertEqual(mode, "prepare")
            self.assertEqual(sql.count("?"), 1)
            self.assertIn("$stmt->execute([$agencyId]);", self.sections[section])
            params = (agency,)
        return [dict(row) for row in self.db.execute(sql, params)]

    def test_manager_receives_only_own_agency_with_original_details(self):
        for agency in ("100001", "100002"):
            rows = self.rows("manager", agency)
            self.assertEqual([row["id"] for row in rows], [agency])
            self.assertEqual(set(rows[0]), {
                "id", "name", "city", "phone", "phone2", "managerName",
                "expireAt", "createdAt", "plan_type",
            })

    def test_consultant_receives_only_own_agency_without_manager_only_fields(self):
        for agency in ("100001", "100002"):
            rows = self.rows("consultant", agency)
            self.assertEqual([row["id"] for row in rows], [agency])
            self.assertEqual(set(rows[0]), {"id", "name", "city", "phone", "phone2", "plan_type"})

    def test_own_properties_include_hidden_and_sold_without_pagination(self):
        for agency in ("100001", "100002"):
            rows = self.rows("own_properties", agency)
            self.assertEqual(len(rows), 4)
            self.assertEqual({row["agencyId"] for row in rows}, {agency})
            self.assertEqual({(row["status"], row["showToGuest"]) for row in rows}, {
                ("موجود", 0), ("موجود", 1), ("واگذار شده", 0), ("واگذار شده", 1),
            })
            self.assertTrue(all(row["phone"] == "private-phone" for row in rows))
        _, sql = self.one_query("own_properties")
        self.assertNotRegex(sql.upper(), r"\b(LIMIT|OFFSET|OR)\b")

    def test_empty_or_missing_agency_does_not_fall_back_to_other_properties(self):
        for agency in ("100003", "missing"):
            self.assertEqual(self.rows("own_properties", agency), [])
        self.assertEqual(self.rows("manager", "missing"), [])
        self.assertEqual(self.rows("consultant", "missing"), [])

    def test_guest_queries_keep_all_agencies_and_only_available_public_properties(self):
        rows = self.rows("guest_agencies")
        self.assertEqual({row["id"] for row in rows}, {"100001", "100002", "100003"})
        self.assertTrue(all(set(row) == {"id", "name", "city", "phone", "phone2", "plan_type"} for row in rows))
        # These are raw DB rows. Guest masking/privacy serialization is unchanged PHP.
        rows = self.rows("guest_properties")
        self.assertEqual({row["id"] for row in rows}, {"100001_public", "100002_public"})

    def test_agency_identifiers_are_bound_as_values(self):
        for section in ("manager", "consultant", "own_properties"):
            self.assertEqual(self.rows(section, "100001' OR 1=1 --"), [])

    def test_own_property_set_matches_what_existing_frontend_already_showed(self):
        frontend = (ROOT / "src/index.html").read_text(encoding="utf-8")
        self.assertIn(
            "properties = Object.values(data.properties || {}).filter(p => p.agencyId === userProfile.agencyId);",
            frontend,
        )
        legacy_sql = "SELECT * FROM properties WHERE agencyId = ? OR (status = 'موجود' AND showToGuest = 1)"
        for agency in ("100001", "100002", "100003"):
            visible_before = {
                row["id"] for row in self.db.execute(legacy_sql, (agency,))
                if row["agencyId"] == agency
            }
            self.assertEqual({row["id"] for row in self.rows("own_properties", agency)}, visible_before)

    def test_demands_and_members_remain_scoped(self):
        private = queries(self.sections["private_lists"])
        self.assertEqual(len(private), 2)
        self.assertEqual(self.sections["private_lists"].count("$stmt->execute([$agencyId]);"), 2)
        for mode, sql in private:
            self.assertEqual(mode, "prepare")
            for agency in ("100001", "100002"):
                rows = [dict(row) for row in self.db.execute(sql, (agency,))]
                self.assertEqual(len(rows), 1)
                self.assertEqual(rows[0]["agencyId"], agency)
                self.assertNotIn("pin", rows[0])

    def test_more_other_agencies_and_public_files_do_not_enlarge_own_results(self):
        before = {section: self.rows(section, "100001") for section in (
            "manager", "consultant", "own_properties",
        )}
        for number in range(1000):
            agency = "other_" + str(number)
            self.db.execute("INSERT INTO agencies (id, name) VALUES (?, ?)", (agency, "دفتر دیگر"))
            self.db.execute(
                "INSERT INTO properties (id, agencyId, status, showToGuest) VALUES (?, ?, ?, ?)",
                ("p_" + agency, agency, "موجود", 1),
            )
        for section, expected in before.items():
            self.assertEqual(self.rows(section, "100001"), expected)
        self.assertEqual(len(self.rows("guest_agencies")), 1003)
        self.assertEqual(len(self.rows("guest_properties")), 1002)


if __name__ == "__main__":
    unittest.main()
