// Short unique ids. Random suffix (not a counter) so ids made in different page loads never collide with ids already in localStorage.
export const nid = (prefix = 'n') => prefix + Math.random().toString(36).slice(2, 9)
