# main.q: hello world in Q, for every trigger
set name = query.name or "World"
if trigger == "schedule" then return "tick from Q"
if trigger == "queue" then return "processed {data.msg} (from Q)"
print "Hello, {name}! (from Q)"
