"""Exercise the actual route lookup SQL against pending/approved enrollments.
Run: python3 tests/enrollment-access.py (SQLite fixture; no application DB changes).
"""
import re
import sqlite3
from pathlib import Path

root = Path(__file__).resolve().parents[1]
db = sqlite3.connect(':memory:')
db.executescript('''
CREATE TABLE users (id INTEGER, full_name TEXT);
CREATE TABLE courses (id INTEGER, title TEXT, description TEXT, category TEXT, course_image TEXT, instructor_id INTEGER);
CREATE TABLE student_enrollments (id INTEGER, student_id INTEGER, course_id INTEGER, is_approved INTEGER DEFAULT 0, progress_percentage INTEGER, is_completed INTEGER, enrollment_date TEXT, completion_date TEXT, approved_at TEXT, approved_by INTEGER);
CREATE TABLE course_lessons (id INTEGER, title TEXT, course_id INTEGER);
CREATE TABLE quizzes (id INTEGER, lesson_id INTEGER);
CREATE TABLE assignments (id INTEGER, lesson_id INTEGER);
CREATE TABLE certificates (id INTEGER, student_id INTEGER, course_id INTEGER, certificate_code TEXT, issued_date TEXT);
INSERT INTO users VALUES (1, 'Learner'), (2, 'Instructor');
INSERT INTO courses VALUES (1, 'Course', '', '', '', 2);
INSERT INTO course_lessons VALUES (1, 'Lesson', 1);
INSERT INTO quizzes VALUES (1, 1);
INSERT INTO assignments VALUES (1, 1);
INSERT INTO student_enrollments (id, student_id, course_id, is_completed) VALUES (1, 1, 1, 1);
''')
queries = {}
for route, table in [('course-lessons', 'student_enrollments'), ('quiz', 'quizzes'), ('assignment', 'assignments'), ('certificate', 'student_enrollments')]:
    source = (root / 'student' / (route + '.php')).read_text()
    queries[route] = next(q for q in re.findall(r"\$db->query\('([\s\S]*?)'\);", source) if 'FROM ' + table + ' ' in q)
params = dict(student_id=1, course_id=1, quiz_id=1, assignment_id=1)
for route, query in queries.items():
    assert db.execute(query, params).fetchone() is None, route + ' leaked pending content'
source = (root / 'admin/enrollments.php').read_text()
approve = next(q for q in re.findall(r"\$db->query\('([\s\S]*?)'\);", source) if q.startswith('UPDATE'))
db.create_function('NOW', 0, lambda: '2026-09-12 12:00:00')
assert db.execute(approve, dict(admin_id=2, id=1)).rowcount == 1
assert db.execute(approve, dict(admin_id=2, id=1)).rowcount == 0
for route, query in queries.items():
    assert db.execute(query, params).fetchone() is not None, route + ' blocked approved student'
    assert db.execute(query, dict(params, student_id=3)).fetchone() is None, route + ' allowed another student'
    key = 'quiz_id' if route == 'quiz' else 'assignment_id' if route == 'assignment' else 'course_id'
    assert db.execute(query, dict(params, **{key: 99})).fetchone() is None, route + ' allowed another course/resource'
print('PASS: all four learning routes deny pending enrollments, allow approved enrollments, and reject other students/resources; repeated approval is harmless.')
