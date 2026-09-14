'use client';
import {useFormStatus} from 'react-dom';
export function Submit({children}:{children:React.ReactNode}){const {pending}=useFormStatus();return <button disabled={pending} type="submit">{pending?'Aguarde…':children}</button>}