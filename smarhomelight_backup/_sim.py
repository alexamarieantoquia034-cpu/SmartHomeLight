import json
b = open('_poll_body.txt').read().strip()
print('BODY:', b)
print()
mi = b.find('\"op_mode\"')
print('first op_mode at', mi)
print('segment from mi:', repr(b[mi:mi+30]))
print('chars mi+11..16:', repr(b[mi+11:mi+17]))
mode = None
if b.find('schedule', mi) == mi + 11: mode = 'schedule'
elif b.find('manual', mi) == mi + 11: mode = 'manual'
elif b.find('auto', mi) == mi + 11: mode = 'auto'
print('firmware parse result:', mode)
all_idx = []
i = -1
while True:
    i = b.find('\"op_mode\"', i+1)
    if i < 0: break
    all_idx.append(i)
print('all op_mode occurrences:', all_idx)
for i in all_idx:
    print('  at', i, '->', repr(b[i:i+22]))
try:
    j = json.loads(b)
    print('json ok | root op_mode =', j.get('op_mode'), '| desired op_mode =', j.get('desired',{}).get('op_mode'))
    print('commands:', j.get('commands'))
except Exception as e:
    print('JSON PARSE ERROR:', e)
