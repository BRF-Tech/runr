import pdfMake from 'pdfmake/build/pdfmake'
import pdfFonts from 'pdfmake/build/vfs_fonts'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
pdfMake.vfs = pdfFonts.vfs

export function useRecoveryCodesPdf() {
  const { t } = useI18n()
  const authStore = useAuthStore()

  function downloadPDF(codes) {
    const email = authStore.user?.email ?? ''
    pdfMake.createPdf({
      content: [
        { text: `RunR — ${email}`, style: 'header' },
        { text: '2FA Kurtarma Kodları', style: 'subheader' },
        { text: t('account.recoveryCodesDesc'), style: 'desc', margin: [0, 4, 0, 16] },
        ...codes.map(c => ({ text: c, style: 'code' })),
      ],
      styles: {
        header:    { fontSize: 18, bold: true, margin: [0, 0, 0, 4] },
        subheader: { fontSize: 13, color: '#555', margin: [0, 0, 0, 8] },
        desc:      { fontSize: 10, color: '#777' },
        code:      { fontSize: 13, bold: true, margin: [0, 3, 0, 3] },
      },
    }).download('recovery-codes.pdf')
  }

  return { downloadPDF }
}
